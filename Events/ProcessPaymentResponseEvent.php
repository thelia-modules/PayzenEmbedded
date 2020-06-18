<?php


namespace PayzenEmbedded\Events;


use Symfony\Component\EventDispatcher\Event;
use Thelia\Core\Event\ActionEvent;

class ProcessPaymentResponseEvent extends ActionEvent
{
    /** @var array $response */
    protected $response;

    /** @var integer $status */
    protected $status;

    public function __construct(array $response = null)
    {
        $this->response = $response;
    }

    /**
     * @return array
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param array $response
     */
    public function setResponse($response)
    {
        $this->response = $response;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }
}