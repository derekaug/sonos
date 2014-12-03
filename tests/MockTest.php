<?php

namespace duncan3dc\Sonos\Test;

use duncan3dc\Sonos\Speaker;
use Mockery;

class MockTest extends \PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();
    }

    public function test()
    {
        $parser = Mockery::mock("duncan3dc\DomParser\XmlParser");
        $device = Mockery::mock("duncan3dc\DomParser\XmlParser");
        $parser->shouldReceive("getTag")->with("device")->once()->andReturn($device);
        $device->shouldReceive("getTag")->with("friendlyName")->once()->andReturn("Test Name");
        $device->shouldReceive("getTag")->with("roomName")->once()->andReturn("Test Room");

        $upnp = Mockery::mock("duncan3dc\Sonos\Device");
        $upnp->shouldReceive("getXml")->with("/xml/device_description.xml")->once()->andReturn($parser);
        $upnp->shouldReceive("soap")->once()->andReturn(3);

        $speaker = new Speaker($upnp);
        $this->assertSame(3, $speaker->getVolume());
    }
}
