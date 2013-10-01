<?php

namespace inplat\rpc\json;

/**
 * Base JSON-RPC 2.0 Client
 *
 * Based on https://github.com/sergeyfast/eazy-jsonrpc
 *
 * @author Konstantin Stepanov
 * @link   http://www.jsonrpc.org/specification
 */
class Client
{

    public $loggerClass = 'inplat\rcp\Logger';
    public $loggerName = 'Client';

    /**
     * Use Objects in Result
     * @var bool
     */
    public $useObjectsInResults = false;

    /**
     * Curl Options
     * @var array
     */
    public $curlOptions = array(
        CURLOPT_POST           => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER     => array( 'Content-Type' => 'application/json' )
    );

    /**
     * Current Request id
     * @var int
     */
    private $id = 1;

    /**
     * Is Batch Call Flag
     * @var bool
     */
    private $isBatchCall = false;

    /**
     * Batch Calls
     * @var Call[]
     */
    private $batchCalls = array();

    /**
     * Batch Notifications
     * @var Call[]
     */
    private $batchNotifications = array();

    /**
     * Create New  client
     * @param string $serverUrl
     * @return Client
     */
    public function __construct( $serverUrl )
    {
        $this->curlOptions[ CURLOPT_URL ] = $serverUrl;
    }

    /**
     * Get Next Request Id
     * @param bool $isNotification
     * @return int
     */
    protected function getRequestId( $isNotification = false )
    {
        return $isNotification ? null : $this->id++;
    }

    /**
     * Begin Batch Call
     * @return bool
     */
    public function beginBatch()
    {
        if ( !$this->isBatchCall )
        {
            $this->batchNotifications = array();
            $this->batchCalls         = array();
            $this->isBatchCall        = true;

            return true;
        }

        return false;
    }

    /**
     * Commit Batch
     */
    public function commitBatch()
    {
        $result = false;
        if ( !$this->isBatchCall || ( !$this->batchCalls && !$this->batchNotifications ) )
        {
            return $result;
        }

        $result = $this->processCalls( array_merge( $this->batchCalls, $this->batchNotifications ) );
        $this->RollbackBatch();

        return $result;
    }

    /**
     * Rollback Calls
     * @return bool
     */
    public function rollbackBatch()
    {
        $this->isBatchCall = false;
        $this->batchCalls  = array();

        return true;
    }

    /**
     * Process Call
     * @param string $method
     * @param array $parameters
     * @param int $id
     * @return mixed
     */
    protected function call( $method, $parameters = array(), $id = null )
    {
        $call = new Call( $method, $parameters, $id );
        if ( $this->isBatchCall )
        {
            if ( $call->id )
            {
                $this->batchCalls[ $call->id ] = $call;
            }
            else
            {
                $this->batchNotifications[ ] = $call;
            }
        }
        else
        {
            $this->processCalls( array( $call ) );
        }

        return $call;
    }

    /**
     * Process Magic Call
     * @param string $method
     * @param array $parameters
     * @return Call
     */
    public function __call( $method, $parameters = array() )
    {
        return $this->call( $method, $parameters, $this->getRequestId() );
    }

    /**
     * Process Calls
     * @param Call[] $calls
     * @return mixed
     */
    protected function processCalls( $calls )
    {
        // Prepare Data
        $singleCall = !$this->isBatchCall ? reset( $calls ) : null;
        $result     = $this->batchCalls ? array_values( array_map( array( __NAMESPACE__ . '\Call', 'getCallData' ), $calls ) ) : Call::getCallData( $singleCall );

        $request = json_encode( $result );

        // Send Curl Request
        $options = $this->curlOptions +
            array(
                CURLOPT_POSTFIELDS     => $request,
                CURLOPT_HEADER         => true,
                CURLINFO_HEADER_OUT    => true,
                CURLOPT_POST           => 1,
                CURLOPT_RETURNTRANSFER => 1,
            );
        $ch      = curl_init();
        curl_setopt_array( $ch, $options );

        $startTime = microtime( true );

        $data = curl_exec( $ch );

        $time          = microtime( true ) - $startTime;
        $headerSize    = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
        $requestHeader = curl_getinfo( $ch, CURLINFO_HEADER_OUT );

        $response = substr( $data, $headerSize );

        $logger              = new $this->loggerClass();
        $logger->serviceName = $this->loggerName;
        $logger->log( $requestHeader, $request, substr( $data, 0, $headerSize ), $response, curl_errno( $ch ), curl_error( $ch ), $time, $startTime );

        $data = json_decode( $response, !$this->useObjectsInResults );

        curl_close( $ch );
        if ( $data === null )
            return false;

        // Process Results for Batch Calls
        if ( $this->batchCalls )
        {
            foreach ( $data as $dataCall )
            {
                // Problem place?
                $key = $this->useObjectsInResults ? $dataCall->id : $dataCall[ 'id' ];
                $this->batchCalls[ $key ]->setResult( $dataCall, $this->useObjectsInResults );
            }
        }
        else
        {
            // Process Results for Call
            $singleCall->setResult( $data, $this->useObjectsInResults );
        }

        return true;
    }

}

