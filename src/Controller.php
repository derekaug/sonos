<?php

namespace duncan3dc\Sonos;

use duncan3dc\DomParser\XmlParser;

/**
 * Allows interaction with the groups of speakers.
 *
 * Although sometimes a Controller is synonymous with a Speaker, when speakers are grouped together only the coordinator can receive events (play/pause/etc)
 */
class Controller extends Speaker
{
    /**
     * No music playing, but not paused.
     *
     * This is a rare state, but can be encountered after an upgrade, or if the queue was cleared
     */
    const STATE_STOPPED = 201;

    /**
     * Currently plating music.
     */
    const STATE_PLAYING = 202;

    /**
     * Music is currently paused.
     */
    const STATE_PAUSED = 203;

    /**
     * The speaker is currently working on either playing or pausing.
     *
     * Check it's state again in a second or two
     */
    const STATE_TRANSITIONING = 204;

    /**
     * The speaker is in an unknown state.
     *
     * This should only happen if Sonos introduce a new state that this code has not been updated to handle.
     */
    const STATE_UNKNOWN = 205;


    /**
     * @var Network $network The network instance this Controller is part of.
     */
    protected $network;


    /**
     * Create a Controller instance from a speaker.
     *
     * The speaker must be a coordinator.
     *
     * @param Speaker $speaker
     */
    public function __construct(Speaker $speaker, Network $network)
    {
        if (!$speaker->isCoordinator()) {
            throw new \InvalidArgumentException("You cannot create a Controller instance from a Speaker that is not the coordinator of it's group");
        }
        $this->ip = $speaker->ip;
        $this->device = $speaker->device;

        $this->network = $network;
        $this->name = $speaker->name;
        $this->room = $speaker->room;
        $this->group = $speaker->getGroup();
        $this->uuid = $speaker->getUuid();
    }


    /**
     * Check if this speaker is the coordinator of it's current group.
     *
     * This method is only here to override the method from the Speaker class.
     * A Controller instance is always the coordinator of it's group.
     *
     * @return boolean
     */
    public function isCoordinator()
    {
        return true;
    }


    /**
     * Get the current state of the group of speakers as the string reported by sonos: PLAYING, PAUSED_PLAYBACK, etc
     *
     * @return string
     */
    public function getStateName()
    {
        $data = $this->soap("AVTransport", "GetTransportInfo");
        return $data["CurrentTransportState"];
    }


    /**
     * Get the current state of the group of speakers.
     *
     * @return int One of the class STATE_ constants
     */
    public function getState()
    {
        $name = $this->getStateName();
        switch ($name) {
            case "STOPPED":
                return self::STATE_STOPPED;
            case "PLAYING":
                return self::STATE_PLAYING;
            case "PAUSED_PLAYBACK":
                return self::STATE_PAUSED;
            case "TRANSITIONING":
                return self::STATE_TRANSITIONING;
        }
        return self::STATE_UNKNOWN;
    }


    /**
     * Get attributes about the currently active track in the queue.
     *
     * @return State Track data containing the following elements
     */
    public function getStateDetails()
    {
        $data = $this->soap("AVTransport", "GetPositionInfo");

        if (!$data["TrackMetaData"]) {
            return new State;
        }

        $parser = new XmlParser($data["TrackMetaData"]);
        $state = State::createFromXml($parser, $this);

        if ((string) $parser->getTag("streamContent")) {
            $info = $this->soap("AVTransport", "GetMediaInfo");
            if (!$state->stream = (string) (new XmlParser($info["CurrentURIMetaData"]))->getTag("title")) {
                $state->stream = (string) $parser->getTag("title");
            }
        }

        $state->queueNumber = (int) $data["Track"];
        $state->duration = $data["TrackDuration"];
        $state->position = $data["RelTime"];

        return $state;
    }


    /**
     * Set the state of the group.
     *
     * @param int $state One of the class STATE_ constants
     *
     * @return void
     */
    public function setState($state)
    {
        switch ($state) {
            case self::STATE_PLAYING:
                return $this->play();
            case self::STATE_PAUSED:
                return $this->pause();
            case self::STATE_STOPPED;
                return $this->pause();
        }
        throw new \InvalidArgumentException("Unknown state (" . $state . ")");
    }


    /**
     * Start playing the active music for this group.
     *
     * @return void
     */
    public function play()
    {
        return $this->soap("AVTransport", "Play", [
            "Speed" =>  1,
        ]);
    }


    /**
     * Pause the group.
     *
     * @return void
     */
    public function pause()
    {
        return $this->soap("AVTransport", "Pause");
    }


    /**
     * Skip to the next track in the current queue
     *
     * @return void
     */
    public function next()
    {
        return $this->soap("AVTransport", "Next");
    }


    /**
     * Skip back to the previous track in the current queue.
     *
     * @return void
     */
    public function previous()
    {
        return $this->soap("AVTransport", "Previous");
    }


    /**
     * Get the speakers that are in the group of this controller.
     *
     * @return Speaker[]
     */
    public function getSpeakers()
    {
        $group = [];
        $speakers = $this->network->getSpeakers();
        foreach ($speakers as $speaker) {
            if ($speaker->getGroup() === $this->getGroup()) {
                $group[] = $speaker;
            }
        }
        return $group;
    }


    /**
     * Adds the specified speaker to the group of this Controller.
     *
     * @param Speaker $speaker The speaker to add to the group
     *
     * @return void
     */
    public function addSpeaker(Speaker $speaker)
    {
        if ($speaker->getUuid() === $this->getUuid()) {
            return;
        }
        $speaker->soap("AVTransport", "SetAVTransportURI", [
            "CurrentURI"            =>  "x-rincon:" . $this->getUuid(),
            "CurrentURIMetaData"    =>  "",
        ]);

        $this->network->clearTopology();
    }


    /**
     * Removes the specified speaker from the group of this Controller.
     *
     * @param Speaker $speaker The speaker to remove from the group
     *
     * @return void
     */
    public function removeSpeaker(Speaker $speaker)
    {
        $speaker->soap("AVTransport", "BecomeCoordinatorOfStandaloneGroup");

        $this->network->clearTopology();
    }


    /**
     * Set the current volume of all the speakers controlled by this Controller.
     *
     * @param int $volume An amount between 0 and 100
     *
     * @return void
     */
    public function setVolume($volume)
    {
        $speakers = $this->getSpeakers();
        foreach ($speakers as $speaker) {
            $speaker->setVolume($volume);
        }
    }


    /**
     * Adjust the volume of all the speakers controlled by this Controller.
     *
     * @param int $adjust A relative amount between -100 and 100
     *
     * @return void
     */
    public function adjustVolume($adjust)
    {
        $speakers = $this->getSpeakers();
        foreach ($speakers as $speaker) {
            $speaker->adjustVolume($adjust);
        }
    }


    /**
     * Get the current play mode settings.
     *
     * @return array An array with 2 boolean elements (shuffle and repeat)
     */
    public function getMode()
    {
        $data = $this->soap("AVTransport", "GetTransportSettings");
        return Helper::getMode($data["PlayMode"]);
    }


    /**
     * Set the current play mode settings.
     *
     * @param array $options An array with 2 boolean elements (shuffle and repeat)
     *
     * @return void
     */
    public function setMode(array $options)
    {
        $data = $this->soap("AVTransport", "SetPlayMode", [
            "NewPlayMode"   =>  Helper::setMode($options),
        ]);
    }


    /**
     * Check if repeat is currently active.
     *
     * @return boolean
     */
    public function getRepeat()
    {
        $mode = $this->getMode();
        return $mode["repeat"];
    }


    /**
     * Turn repeat mode on or off.
     *
     * @param boolean $repeat Whether repeat should be on or not
     *
     * @return void
     */
    public function setRepeat($repeat)
    {
        $repeat = (boolean) $repeat;

        $mode = $this->getMode();
        if ($mode["repeat"] === $repeat) {
            return;
        }

        $mode["repeat"] = $repeat;
        $this->setMode($mode);
    }


    /**
     * Check if shuffle is currently active.
     *
     * @return boolean
     */
    public function getShuffle()
    {
        $mode = $this->getMode();
        return $mode["shuffle"];
    }


    /**
     * Turn shuffle mode on or off.
     *
     * @param boolean $shuffle Whether shuffle should be on or not
     *
     * @return void
     */
    public function setShuffle($shuffle)
    {
        $shuffle = (boolean) $shuffle;

        $mode = $this->getMode();
        if ($mode["shuffle"] === $shuffle) {
            return;
        }

        $mode["shuffle"] = $shuffle;
        $this->setMode($mode);
    }


    /**
     * Check if crossfade is currently active.
     *
     * @return boolean
     */
    public function getCrossfade()
    {
        return (boolean) $this->soap("AVTransport", "GetCrossfadeMode");
    }


    /**
     * Turn crossfade on or off.
     *
     * @param boolean $crossfade Whether crossfade should be on or not
     *
     * @return void
     */
    public function setCrossfade($crossfade)
    {
        $data = $this->soap("AVTransport", "SetCrossfadeMode", [
            "CrossfadeMode" =>  (boolean) $crossfade,
        ]);
    }


    /**
     * Get the queue for this controller.
     *
     * @return Queue
     */
    public function getQueue()
    {
        return new Queue($this);
    }
}
