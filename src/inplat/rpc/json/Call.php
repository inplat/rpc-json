<?php

namespace inplat\rpc\json;

/**
 * Base JSON-RPC 2.0 Call
 *
 * Based on https://github.com/sergeyfast/eazy-jsonrpc
 *
 * @author Konstantin Stepanov
 * @link   http://www.jsonrpc.org/specification
 */
class Call
{

    /** @var int */
    public $id;

    /** @var string */
    public $method;

    /** @var array */
    public $params;

    /** @var array */
    public $error;

    /** @var mixed */
    public $result;

    /**
     * Has Error
     * @return bool
     */
    public function hasError()
    {
        return !empty( $this->error );
    }

    /**
     * @param string $method
     * @param array  $params
     * @param string $id
     */
    public function __construct( $method, $params, $id )
    {
        $this->method = $method;
        $this->params = $params;
        $this->id     = $id;
    }

    /**
     * Get Call Data
     * @param Call $call
     * @return array
     */
    public static function getCallData( Call $call )
    {
        return array(
            'jsonrpc' => '2.0',
            'id'      => $call->id,
            'method'  => $call->method,
            'params'  => $call->params
        );
    }

    /**
     * Set Result
     * @param mixed $data
     * @param bool  $useObjects
     */
    public function setResult( $data, $useObjects = false )
    {
        if ( $useObjects )
        {
            $this->error  = property_exists( $data, 'error' ) ? $data->error : null;
            $this->result = property_exists( $data, 'result' ) ? $data->result : null;
        }
        else
        {
            $this->error  = isset( $data['error'] ) ? $data['error'] : null;
            $this->result = isset( $data['result'] ) ? $data['result'] : null;
        }
    }

}
