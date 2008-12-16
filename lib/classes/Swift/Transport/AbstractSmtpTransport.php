<?php

/*
 The ESMTP Transport from Swift Mailer.
 
 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.
 
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.
 
 */

//@require 'Swift/Transport.php';
//@require 'Swift/Transport/IoBuffer.php';
//@require 'Swift/Transport/CommandSentException.php';
//@require 'Swift/Transport/TransportException.php';
//@require 'Swift/Mime/Message.php';
//@require 'Swift/Events/EventDispatcher.php';
//@require 'Swift/Events/EventListener.php';

/**
 * Sends Messages over SMTP.
 * @package Swift
 * @subpackage Transport
 * @author Chris Corbyn
 */
abstract class Swift_Transport_AbstractSmtpTransport
  implements Swift_Transport
{
  
  /** Input-Output buffer for sending/receiving SMTP commands and responses */
  protected $_buffer;
  
  /** Connection status */
  protected $_started = false;
  
  /** The domain name to use in HELO command */
  protected $_domain = 'localhost';
  
  /** The event dispatching layer */
  protected $_eventDispatcher;
  
  /** Loaded plugins */
  protected $_plugins = array();
  
  /** Return an array of params for the Buffer */
  abstract protected function _getBufferParams();
  
  /**
   * Creates a new EsmtpTransport using the given I/O buffer.
   * @param Swift_Transport_IoBuffer $buf
   * @param Swift_Events_EventDispatcher $dispatcher
   */
  public function __construct(Swift_Transport_IoBuffer $buf,
    Swift_Events_EventDispatcher $dispatcher)
  {
    $this->_eventDispatcher = $dispatcher;
    $this->_buffer = $buf;
  }
  
  /**
   * Set the name of the local domain which Swift will identify itself as.
   * This should be a fully-qualified domain name and should be truly the domain
   * you're using.  If your server doesn't have a domain name, use the IP in square
   * brackets (i.e. [127.0.0.1]).
   * @param string $domain
   */
  public function setLocalDomain($domain)
  {
    $this->_domain = $domain;
    return $this;
  }
  
  /**
   * Get the name of the domain Swift will identify as.
   * @return string
   */
  public function getLocalDomain()
  {
    return $this->_domain;
  }
  
  /**
   * Start the SMTP connection.
   */
  public function start()
  {
    if (!$this->_started)
    {      
      try
      {
        $this->_buffer->initialize($this->_getBufferParams());
      }
      catch (Swift_Transport_TransportException $e)
      {
        $this->_throwException($e);
      }
      $this->_readGreeting();
      $this->_doHeloCommand();
      
      if ($evt = $this->_eventDispatcher->createTransportChangeEvent($this))
      {
        $this->_eventDispatcher->dispatchEvent($evt, 'transportStarted');
      }
      
      $this->_started = true;
    }
  }
  
  /**
   * Test if an SMTP connection has been established.
   * @return boolean
   */
  public function isStarted()
  {
    return $this->_started;
  }
  
  /**
   * Send the given Message.
   * Recipient/sender data will be retreived from the Message API.
   * The return value is the number of recipients who were accepted for delivery.
   * @param Swift_Mime_Message $message
   * @param string[] &$failedRecipients to collect failures by-reference
   * @return int
   */
  public function send(Swift_Mime_Message $message, &$failedRecipients = null)
  {
    $sent = 0;
    $failedRecipients = (array) $failedRecipients;
    
    if (!$reversePath = $this->_getReversePath($message))
    {
      throw new Swift_Transport_TransportException(
        'Cannot send message without a sender address'
        );
    }
    
    if ($evt = $this->_eventDispatcher->createSendEvent($this, $message))
    {
      $this->_eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
      if ($evt->bubbleCancelled())
      {
        return 0;
      }
    }
    
    $to = (array) $message->getTo();
    $cc = (array) $message->getCc();
    $bcc = (array) $message->getBcc();
    
    $message->setBcc(array());
    
    try
    {
      $sent += $this->_sendTo($message, $reversePath, $to, $failedRecipients);
      $sent += $this->_sendCc($message, $reversePath, $cc, $failedRecipients);
      $sent += $this->_sendBcc($message, $reversePath, $bcc, $failedRecipients);
    }
    catch (Exception $e)
    {
      $message->setBcc($bcc);
      throw $e;
    }
    
    $message->setBcc($bcc);
    
    if ($evt)
    {
      $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
      $evt->setFailedRecipients($failedRecipients);
      $this->_eventDispatcher->dispatchEvent($evt, 'sendPerformed');
    }
    
    return $sent;
  }
  
  /**
   * Stop the SMTP connection.
   */
  public function stop()
  {
    if ($this->_started)
    {
      try
      {
        $this->executeCommand("QUIT\r\n", array(221));
      }
      catch (Swift_Transport_TransportException $e) {}
      
      try
      {
        $this->_buffer->terminate();
      
        if ($evt = $this->_eventDispatcher->createTransportChangeEvent($this))
        {
          $this->_eventDispatcher->dispatchEvent($evt, 'transportStopped');
        }
      }
      catch (Swift_Transport_TransportException $e)
      {
        $this->_throwException($e);
      }
    }
    $this->_started = false;
  }
  
  /**
   * Register a plugin using a known unique key (e.g. myPlugin).
   * @param Swift_Events_EventListener $plugin
   * @param string $key
   */
  public function registerPlugin(Swift_Events_EventListener $plugin, $key)
  {
    if (isset($this->_plugins[$key]) && $this->_plugins[$key] === $plugin)
    {
      return; //already loaded
    }
    
    $this->_eventDispatcher->bindEventListener($plugin);
    $this->_plugins[$key] = $plugin;
  }
  
  /**
   * Reset the current mail transaction.
   */
  public function reset()
  {
    $this->executeCommand("RSET\r\n", array(250));
  }
  
  /**
   * Get the IoBuffer where read/writes are occurring.
   * @return Swift_Transport_IoBuffer
   */
  public function getBuffer()
  {
    return $this->_buffer;
  }
  
  /**
   * Run a command against the buffer, expecting the given response codes.
   * If no response codes are given, the response will not be validated.
   * If codes are given, an exception will be thrown on an invalid response.
   * @param string $command
   * @param int[] $codes
   * @param string[] &$failures
   * @return string
   */
  public function executeCommand($command, $codes = array(), &$failures = null)
  {
    $failures = (array) $failures;
    $seq = $this->_buffer->write($command);
    $response = $this->_getFullResponse($seq);
    if ($evt = $this->_eventDispatcher->createCommandEvent($this, $command, $codes))
    {
      $this->_eventDispatcher->dispatchEvent($evt, 'commandSent');
    }
    $this->_assertResponseCode($response, $codes);
    return $response;
  }
  
  // -- Protected methods
  
  /** Read the opening SMTP greeting */
  protected function _readGreeting()
  {
    $this->_assertResponseCode($this->_getFullResponse(0), array(220));
  }
  
  /** Send the HELO welcome */
  protected function _doHeloCommand()
  {
    $this->executeCommand(
      sprintf("HELO %s\r\n", $this->_domain), array(250)
      );
  }
  
  /** Send the MAIL FROM command */
  protected function _doMailFromCommand($address)
  {
    $this->executeCommand(
      sprintf("MAIL FROM: <%s>\r\n", $address), array(250)
      );
  }
  
  /** Send the RCPT TO command */
  protected function _doRcptToCommand($address)
  {
    $this->executeCommand(
      sprintf("RCPT TO: <%s>\r\n", $address), array(250, 251, 252)
      );
  }
  
  /** Send the DATA command */
  protected function _doDataCommand()
  {
    $this->executeCommand("DATA\r\n", array(354));
  }
  
  /** Stream the contents of the message over the buffer */
  protected function _streamMessage(Swift_Mime_Message $message)
  {
    $this->_buffer->setWriteTranslations(array("\r\n." => "\r\n.."));
    try
    {
      $message->toByteStream($this->_buffer);
      $this->_buffer->flushBuffers();
    }
    catch (Swift_Transport_TransportException $e)
    {
      $this->_throwException($e);
    }
    $this->_buffer->setWriteTranslations(array());
    $this->executeCommand("\r\n.\r\n", array(250));
  }
  
  /** Determine the best-use reverse path for this message */
  protected function _getReversePath(Swift_Mime_Message $message)
  {
    $return = $message->getReturnPath();
    $sender = $message->getSender();
    $from = $message->getFrom();
    $path = null;
    if (!empty($return))
    {
      $path = $return;
    }
    elseif (!empty($sender))
    {
      $keys = array_keys($sender);
      $path = array_shift($keys);
    }
    elseif (!empty($from))
    {
      $keys = array_keys($from);
      $path = array_shift($keys);
    }
    return $path;
  }
  
  /** Throw a TransportException, first sending it to any listeners */
  protected function _throwException(Swift_Transport_TransportException $e)
  {
    if ($evt = $this->_eventDispatcher->createTransportExceptionEvent($this, $e))
    {
      $this->_eventDispatcher->dispatchEvent($evt, 'exceptionThrown');
      if (!$evt->bubbleCancelled())
      {
        throw $e;
      }
    }
    else
    {
      throw $e;
    }
  }
  
  /** Throws an Exception if a response code is incorrect */
  protected function _assertResponseCode($response, $wanted)
  {
    list($code, $separator, $text) = sscanf($response, '%3d%[ -]%s');
    $valid = (empty($wanted) || in_array($code, $wanted));
    
    if ($evt = $this->_eventDispatcher->createResponseEvent($this, $response,
      $valid))
    {
      $this->_eventDispatcher->dispatchEvent($evt, 'responseReceived');
    }
    
    if (!$valid)
    {
      $this->_throwException(
        new Swift_Transport_TransportException(
          'Expected response code ' . implode('/', $wanted) . ' but got code ' .
          '"' . $code . '", with message "' . $response . '"'
          )
        );
    }
  }
  
  /** Get an entire multi-line response using its sequence number */
  protected function _getFullResponse($seq)
  {
    $response = '';
    try
    {
      do
      {
        $line = $this->_buffer->readLine($seq);
        $response .= $line;
      }
      while (null !== $line && false !== $line && ' ' != $line{3});
    }
    catch (Swift_Transport_TransportException $e)
    {
      $this->_throwException($e);
    }
    return $response;
  }
  
  // -- Private methods
  
  /** Send an email to the given recipients from the given reverse path */
  private function _doMailTransaction($message, $reversePath,
    array $recipients, array &$failedRecipients)
  {
    $sent = 0;
    $this->_doMailFromCommand($reversePath);
    foreach ($recipients as $forwardPath)
    {
      try
      {
        $this->_doRcptToCommand($forwardPath);
        $sent++;
      }
      catch (Swift_Transport_TransportException $e)
      {
        $failedRecipients[] = $forwardPath;
      }
    }
    
    if ($sent != 0)
    {
      $this->_doDataCommand();
      $this->_streamMessage($message);
    }
    else
    {
      $this->reset();
    }
    
    return $sent;
  }
  
  /** Send a message to the given To: recipients */
  private function _sendTo(Swift_Mime_Message $message, $reversePath,
    array $to, array &$failedRecipients)
  {
    return $this->_doMailTransaction($message, $reversePath, array_keys($to),
      $failedRecipients);
  }
  
  /** Send a message to the given Cc: recipients */
  private function _sendCc(Swift_Mime_Message $message, $reversePath,
    array $cc, array &$failedRecipients)
  {
    return $this->_doMailTransaction($message, $reversePath, array_keys($cc),
      $failedRecipients);
  }
  
  /** Send a message to all Bcc: recipients */
  private function _sendBcc(Swift_Mime_Message $message, $reversePath,
    array $bcc, array &$failedRecipients)
  {
    $sent = 0;
    foreach ($bcc as $forwardPath => $name)
    {
      $message->setBcc(array($forwardPath => $name));
      $sent += $this->_doMailTransaction(
        $message, $reversePath, array($forwardPath), $failedRecipients
        );
    }
    return $sent;
  }
  
  /**
   * Destructor.
   */
  public function __destruct()
  {
    $this->stop();
  }
  
}