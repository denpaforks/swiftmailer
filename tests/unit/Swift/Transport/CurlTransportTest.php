<?php

class Swift_Transport_CurlTransportTest extends \SwiftMailerTestCase
{
    protected function _getTransport()
    {
        $dispatcher = $this->_createEventDispatcher();

        return new Swift_Transport_CurlTransport($dispatcher);
    }

    public function testHostCanBeSetAndFetched()
    {
        $smtp = $this->_getTransport();
        $smtp->setHost('foo');
        $this->assertEquals('foo', $smtp->getHost(), '%s: Host should be returned');
    }

    public function testPortCanBeSetAndFetched()
    {
        $smtp = $this->_getTransport();
        $smtp->setPort(25);
        $this->assertEquals(25, $smtp->getPort(), '%s: Port should be returned');
    }

    public function testTimeoutCanBeSetAndFetched()
    {
        $smtp = $this->_getTransport();
        $smtp->setTimeout(10);
        $this->assertEquals(10, $smtp->getTimeout(), '%s: Timeout should be returned');
    }

    public function testEncryptionCanBeSetAndFetched()
    {
        $smtp = $this->_getTransport();
        $smtp->setEncryption('tls');
        $this->assertEquals('tls', $smtp->getEncryption(), '%s: Crypto should be returned');
    }

    public function testFluidInterface()
    {
        $smtp = $this->_getTransport();
        $ref = $smtp
            ->setHost('foo')
            ->setPort(25)
            ->setEncryption('tls')
            ->setTimeout(30)
            ;
        $this->assertEquals($ref, $smtp);
    }

    // -- Creation Methods
    private function _createEventDispatcher()
    {
        return $this->getMockery('Swift_Events_EventDispatcher')->shouldIgnoreMissing();
    }
}
