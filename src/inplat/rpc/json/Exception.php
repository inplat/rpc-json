<?php

namespace inplat\rpc\json;

/**
 * Base JSON-RPC 2.0 RcpException
 *
 *
 * @author Konstantin Stepanov
 * @link   http://www.jsonrpc.org/specification
 */
class Exception extends \Exception
{

    const PARSE_ERROR      = -32700;
    const INVALID_REQUEST  = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS   = -32602;
    const INTERNAL_ERROR   = -32603;

    public function __construct( $code = 0, $message = "", Exception $previous = null )
    {
        parent::__construct( $message, $code, $previous );

        if ( empty( $message ) )
        {
            $this->message = static::getMessageByCode( $code );
        }
    }

    public static function getMessageByCode( $code )
    {
        switch ( $code )
        {
            case static::PARSE_ERROR :
                return 'Parse error';
            case static::INVALID_REQUEST :
                return 'Invalid Request';
            case static::METHOD_NOT_FOUND:
                return 'Method not found';
            case static::INVALID_PARAMS :
                return 'Invalid params';
            case static::INTERNAL_ERROR :
                return 'Internal error';
        }
        return 'Internal error';
    }

}
