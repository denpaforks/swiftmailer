<?php

/*
 * (c) 2016 Denis Paavilainen <denpa@netfleet.space>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Sends message through cURL.
 *
 * @author  Denis Paavilainen
 */
class Swift_CurlTransport extends Swift_Transport_CurlTransport
{
    /**
     * Create a new CurlTransport.
     */
    public function __construct($host = 'localhost', $port = 25, $security = null)
    {
        call_user_func_array(
            array($this, 'Swift_Transport_CurlTransport::__construct'),
            Swift_DependencyContainer::getInstance()
                ->createDependenciesFor('transport.curl')
        );

        $this->setHost($host);
        $this->setPort($port);
    }

    /**
     * Create a new CurlTransport instance.
     *
     * @return Swift_CurlTransport
     */
    public static function newInstance($host = 'localhost', $port = 25, $security = null)
    {
        return new self($host, $port, $security);
    }
}
