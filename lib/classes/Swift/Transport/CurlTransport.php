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
class Swift_Transport_CurlTransport implements Swift_Transport
{
    /** The event dispatcher from the plugin API */
    private $_eventDispatcher;

    /** The domain name to use in HELO command */
    protected $_domain = '[127.0.0.1]';

    /** Curl handle */
    protected $_curl;

    /** Curl status */
    protected $_started = false;

    /** Curl payload buffer */
    protected $_buffer = array();

    /**
     * Connection parameters.
     *
     * @var array
     */
    private $_params = array(
        'host' => 'localhost',
        'port' => 25,
        'timeout' => 30,
        'protocol' => 'http',
    );

    /**
     * Constructor.
     */
    public function __construct(Swift_Events_EventDispatcher $eventDispatcher)
    {
        $this->_eventDispatcher = $eventDispatcher;
        $this->_lookupHostname();
    }

    /**
     * Test if cURL has been initilized.
     *
     * @return bool
     */
    public function isStarted()
    {
        return $this->_started;
    }

    /**
     * Start the SMTP connection.
     */
    public function start()
    {
        if (!$this->_started) {
            $this->_doCurlInit();
            $this->_setCurlOptions();
            $this->_setCurlHost();

            $this->_doHeloCommand();
            $this->_doAuthCommand();

            $this->_started = true;
        }
    }

    /**
     * Close curl
     */
    public function stop()
    {
        if( is_resource($this->_curl) ) {
            curl_close($this->_curl);
        }
    }

    /**
     * Set the host to connect to.
     *
     * @param string $host
     *
     * @return Swift_Transport_EsmtpTransport
     */
    public function setHost($host)
    {
        $this->_params['host'] = $host;

        return $this;
    }

    /**
     * Get the host to connect to.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->_params['host'];
    }

    /**
     * Set the port to connect to.
     *
     * @param int     $port
     *
     * @return Swift_Transport_EsmtpTransport
     */
    public function setPort($port)
    {
        $this->_params['port'] = (int) $port;

        return $this;
    }

    /**
     * Get the port to connect to.
     *
     * @return int
     */
    public function getPort()
    {
        return $this->_params['port'];
    }

    /**
     * Set the connection timeout.
     *
     * @param int     $timeout seconds
     *
     * @return Swift_Transport_EsmtpTransport
     */
    public function setTimeout($timeout)
    {
        $this->_params['timeout'] = (int) $timeout;
        if( !is_resource($this->_curl) ) {
            $this->_doCurlInit();
        }
        curl_setopt( $this->_curl, CURLOPT_CONNECTTIMEOUT, (int) $timeout );

        return $this;
    }

    /**
     * Get the connection timeout.
     *
     * @return int
     */
    public function getTimeout()
    {
        return $this->_params['timeout'];
    }

    /**
     * Set the encryption type (tls or ssl)
     *
     * @param string $encryption
     *
     * @return Swift_Transport_EsmtpTransport
     */
    public function setEncryption($encryption)
    {
        $encryption = strtolower($encryption);

        if ('ssl' == $encryption) {
            $this->_params['protocol'] = 'https';
        }
        $this->_params['encryption'] = $encryption;

        return $this;
    }

    /**
     * Get the encryption type
     *
     * @return string
     */
    public function getEncryption()
    {
        return $this->_params['encryption'];
    }

    /**
     * Set the username to authenticate with.
     *
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->_username = $username;

        return $this;
    }

    /**
     * Get the username to authenticate with.
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->_username;
    }

    /**
     * Set the password to authenticate with.
     *
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->_password = $password;

        return $this;
    }

    /**
     * Get the password to authenticate with.
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->_password;
    }

    /** Try to determine the hostname of the server this is run on */
    private function _lookupHostname()
    {
        if (!empty($_SERVER['SERVER_NAME'])
            && $this->_isFqdn($_SERVER['SERVER_NAME'])) {
            $this->_domain = $_SERVER['SERVER_NAME'];
        } elseif (!empty($_SERVER['SERVER_ADDR'])) {
            $this->_domain = sprintf('[%s]', $_SERVER['SERVER_ADDR']);
        }
    }

    /** Determine is the $hostname is a fully-qualified name */
    private function _isFqdn($hostname)
    {
        // We could do a really thorough check, but there's really no point
        if (false !== $dotPos = strpos($hostname, '.')) {
            return ($dotPos > 0) && ($dotPos != strlen($hostname) - 1);
        } else {
            return false;
        }
    }

    /** Initialize curl */
    private function _doCurlInit() {
        if( !is_resource($this->_curl) ) {
            $this->_curl = curl_init();
        }
    }

    /** Set curl options */
    private function _setCurlOptions() {
        if( !is_resource($this->_curl) ) {
            return false;
        }

        if('https' == $this->_params['protocol']) {
            curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, true);
            curl_setopt($this->_curl, CURLOPT_CAINFO, dirname(__FILE__).'/cacert.pem');
        }

        curl_setopt($this->_curl, CURLOPT_HTTPHEADER, array());
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
    }

    /** Set curl host */
    private function _setCurlHost() {
        if( !is_resource($this->_curl) ) {
            return false;
        }

        curl_setopt($this->_curl, CURLOPT_URL, $this->_params['protocol'] . '://' . $this->getHost() . ':' . $this->getPort() );
    }

    /** Send the HELO welcome */
    protected function _doHeloCommand()
    {
        $this->_buffer['helo'] = sprintf("HELO %s\r\n", $this->_domain);
    }

    /** Do auth command */
    protected function _doAuthCommand()
    {
        $this->_buffer['auth'] = sprintf("AUTH LOGIN\r\n%s\r\n%s\r\n", base64_encode( $this->getUsername() ), base64_encode( $this->getPassword() ));
    }

    /** Send the MAIL FROM command */
    protected function _doMailFromCommand($address)
    {
        $this->_buffer['from'] = sprintf("MAIL FROM: <%s>\r\n", $address);
    }

    /** Send the RCPT TO command */
    protected function _doRcptToCommand($address)
    {
        $rcptTo = sprintf("RCPT TO: <%s>\r\n", $address);
        $this->_buffer['to'] = !isset($this->_buffer['to']) ? $rcptTo : $this->_buffer['to'] . $rcptTo;
    }

    /** Send the DATA command */
    protected function _doDataCommand()
    {
        $this->_buffer['data'] = "DATA\r\n";
    }

    /** Stream the contents of the message over the buffer */
    protected function _streamMessage(Swift_Mime_Message $message)
    {
        if( !is_resource($this->_curl) ) {
            return false;
        }

        $this->_buffer['message'] = sprintf("%s\r\n", $message->toString());
        $this->_buffer['message'] .= "\r\n.\r\n";
        $this->_buffer['message'] .= "QUIT\r\n";

        curl_setopt($this->_curl, CURLOPT_CUSTOMREQUEST, implode('', $this->_buffer) . "\r\n");

        return curl_exec($this->_curl);
    }

    /**
     * Sends the given message.
     *
     * @param Swift_Mime_Message $message
     * @param string[]           $failedRecipients An array of failures by-reference
     *
     * @return int     The number of sent emails
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $sent = 0;
        $failedRecipients = (array) $failedRecipients;

        if ($evt = $this->_eventDispatcher->createSendEvent($this, $message)) {
            $this->_eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        if (!$reversePath = $this->_getReversePath($message)) {
            throw new Swift_TransportException(
                'Cannot send message without a sender address'
                );
        }

        $toHeader = $message->getHeaders()->get('To');
        $fromHeader = $message->getHeaders()->get('From');
        if (!$toHeader) {
            throw new Swift_TransportException(
                'Cannot send message without a recipient'
                );
        }

        $to =  $toHeader->getFieldBody();
        $from = $fromHeader->getFieldBody();

        $reversePath = $this->_getReversePath($message);

        $to = (array) $message->getTo();
        $cc = (array) $message->getCc();
        $tos = array_merge($to, $cc);
        $bcc = (array) $message->getBcc();

        $message->setBcc(array());

        try {
            $sent += $this->_sendTo($message, $reversePath, $tos, $failedRecipients);
            $sent += $this->_sendBcc($message, $reversePath, $bcc, $failedRecipients);
        } catch (Exception $e) {
            $message->setBcc($bcc);
            throw $e;
        }

        $message->setBcc($bcc);

        if ($evt) {
            if ($sent == count($to) + count($cc) + count($bcc)) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } elseif ($sent > 0) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_TENTATIVE);
            } else {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }
            $evt->setFailedRecipients($failedRecipients);
            $this->_eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        $message->generateId(); //Make sure a new Message ID is used

        return $sent;
    }

    /** Send a message to the given To: recipients */
    private function _sendTo(Swift_Mime_Message $message, $reversePath, array $to, array &$failedRecipients)
    {
        if (empty($to)) {
            return 0;
        }

        return $this->_doMailTransaction($message, $reversePath, array_keys($to),
            $failedRecipients);
    }

    /** Send a message to all Bcc: recipients */
    private function _sendBcc(Swift_Mime_Message $message, $reversePath, array $bcc, array &$failedRecipients)
    {
        $sent = 0;
        foreach ($bcc as $forwardPath => $name) {
            $message->setBcc(array($forwardPath => $name));
            $sent += $this->_doMailTransaction(
                $message, $reversePath, array($forwardPath), $failedRecipients
                );
        }

        return $sent;
    }

    /** Send an email to the given recipients from the given reverse path */
    private function _doMailTransaction($message, $reversePath, array $recipients, array &$failedRecipients)
    {
        $sent = 0;
        $this->_doMailFromCommand($reversePath);
        foreach ($recipients as $forwardPath) {
            try {
                $this->_doRcptToCommand($forwardPath);
                $sent++;
            } catch (Swift_TransportException $e) {
                $failedRecipients[] = $forwardPath;
            }
        }

        if ($sent != 0) {
            $this->_doDataCommand();
            $this->_streamMessage($message);
        } else {
            $this->reset();
        }

        return $sent;
    }

    /**
     * Register a plugin.
     *
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->_eventDispatcher->bindEventListener($plugin);
    }

    /** Determine the best-use reverse path for this message */
    private function _getReversePath(Swift_Mime_Message $message)
    {
        $return = $message->getReturnPath();
        $sender = $message->getSender();
        $from = $message->getFrom();
        $path = null;
        if (!empty($return)) {
            $path = $return;
        } elseif (!empty($sender)) {
            $keys = array_keys($sender);
            $path = array_shift($keys);
        } elseif (!empty($from)) {
            $keys = array_keys($from);
            $path = array_shift($keys);
        }

        return $path;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->stop();
    }
}
