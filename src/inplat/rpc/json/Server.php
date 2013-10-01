<?php

namespace inplat\rpc\json;

/**
 * Base JSON-RPC 2.0 Service
 *
 * Based on https://github.com/sergeyfast/eazy-jsonrpc
 *
 * @author Konstantin Stepanov
 * @link   http://www.jsonrpc.org/specification
 */
class Server
{

    public $loggerClass = 'inplat\rcp\Logger';
    public $loggerName = 'Server';

    public $showSmd = true;

    /**
     * Exposed Instance
     * @var object
     */
    protected $instance;

    /**
     * Decoded Json Request
     * @var object|array
     */
    protected $request;

    /**
     * Array of Received Calls
     * @var array
     */
    protected $calls = array();

    /**
     * Array of Responses for Calls
     * @var array
     */
    protected $response = array();

    /**
     * Has Calls Flag (not notifications)
     * @var bool
     */
    protected $hasCalls = false;

    /**
     * Is Batch Call in using
     * @var bool
     */
    private $isBatchCall = false;

    /**
     * Hidden Methods
     * @var array
     */
    protected $hiddenMethods = array(
        'handle', '__construct'
    );

    /**
     * Content Type
     * @var string
     */
    public $contentType = 'application/json';

    /**
     * Alow Cross-Domain Requests
     * @var bool
     */
    public $isXDR = true;

    /**
     * Error Messages
     * @var array
     */
    protected $errorMessages = array(
        Exception::PARSE_ERROR      => 'Parse error',
        Exception::INVALID_REQUEST  => 'Invalid Request',
        Exception::METHOD_NOT_FOUND => 'Method not found',
        Exception::INVALID_PARAMS   => 'Invalid params',
        Exception::INTERNAL_ERROR   => 'Internal error'
    );

    /**
     * Cached Reflection Methods
     * @var \ReflectionMethod[]
     */
    private $reflectionMethods = array();

    /**
     * Validate Request
     * @return int error
     */
    private function getRequest()
    {
        $error = null;

        do
        {
            if ( array_key_exists( 'REQUEST_METHOD', $_SERVER ) && $_SERVER[ 'REQUEST_METHOD' ] != 'POST' )
            {
                $error = Exception::INVALID_REQUEST;
                break;
            }
            ;

            $request       = !empty( $_GET[ 'rawRequest' ] ) ? $_GET[ 'rawRequest' ] : file_get_contents( 'php://input' );
            $this->request = json_decode( $request, false );
            if ( $this->request === null )
            {
                $error = Exception::PARSE_ERROR;
                break;
            }

            if ( $this->request === array() )
            {
                $error = Exception::INVALID_REQUEST;
                break;
            }

            // check for batch call
            if ( is_array( $this->request ) )
            {
                $this->calls       = $this->request;
                $this->isBatchCall = true;
            }
            else
            {
                $this->calls[ ] = $this->request;
            }
        }
        while ( false );

        return $error;
    }

    /**
     * Get Error Response
     * @param int   $code
     * @param mixed $id
     * @param null  $debug
     * @return array
     */
    private function getError( $code, $id = null, $detail = null, $message = null, $debug = null )
    {
        $res = array(
            'jsonrpc' => '2.0',
            'error'   => array(
                'code'    => $code,
                'message' => $message !== null ? $message : Exception::getMessageByCode( $code ),
                'detail'  => $detail
            ),
            'id'      => $id
        );

        if ( DEBUG )
            $res[ 'error' ][ 'debug' ] = $debug;

        return $res;
    }

    /**
     * Check for jsonrpc version and correct method
     * @param object $call
     * @return array|null
     */
    private function validateCall( $call )
    {
        $result = null;
        $error  = null;
        $data   = null;
        $id     = is_object( $call ) && property_exists( $call, 'id' ) ? $call->id : null;
        do
        {
            if ( !is_object( $call ) )
            {
                $error = Exception::INVALID_REQUEST;
                break;
            }

            // hack for inputEx smd tester
            if ( property_exists( $call, 'version' ) )
            {
                if ( $call->version == 'json-rpc-2.0' )
                {
                    $call->jsonrpc = '2.0';
                }
            }

            if ( !property_exists( $call, 'jsonrpc' ) || $call->jsonrpc != '2.0' )
            {
                $error = Exception::INVALID_REQUEST;
                break;
            }

            $method = property_exists( $call, 'method' ) ? $call->method : null;
            if ( !$method || !method_exists( $this->instance, $method ) || in_array( strtolower( $method ), $this->hiddenMethods ) )
            {
                $error = Exception::METHOD_NOT_FOUND;
                break;
            }

            if ( !array_key_exists( $method, $this->reflectionMethods ) )
            {
                $this->reflectionMethods[ $method ] = new \ReflectionMethod( $this->instance, $method );
            }

            /** @var $params array */
            $params     = property_exists( $call, 'params' ) ? $call->params : null;
            $paramsType = gettype( $params );
            if ( $params !== null && $paramsType != 'array' && $paramsType != 'object' )
            {
                $error = Exception::INVALID_PARAMS;
                break;
            }

            // check parameters
            switch ( $paramsType )
            {
                case 'array':
                    $totalRequired = 0;
                    // doesn't hold required, null, required sequence of params
                    foreach ( $this->reflectionMethods[ $method ]->getParameters() as $param )
                    {
                        if ( !$param->isDefaultValueAvailable() )
                        {
                            $totalRequired++;
                        }
                    }

                    if ( count( $params ) < $totalRequired )
                    {
                        $error = Exception::INVALID_PARAMS;
                        $data  = sprintf( 'Check numbers of required params (got %d, expected %d)', count( $params ), $totalRequired );
                    }
                    break;
                case 'object':
                    foreach ( $this->reflectionMethods[ $method ]->getParameters() as $param )
                    {
                        if ( !$param->isDefaultValueAvailable() && !array_key_exists( $param->getName(), $params ) )
                        {
                            $error = Exception::INVALID_PARAMS;
                            $data  = $param->getName() . ' not found';

                            break 3;
                        }
                    }
                    break;
                case 'NULL':
                    if ( $this->reflectionMethods[ $method ]->getNumberOfRequiredParameters() > 0 )
                    {
                        $error = Exception::INVALID_PARAMS;
                        $data  = 'Empty required params';
                        break 2;
                    }
                    break;
            }
        }
        while ( false );

        if ( $error )
        {
            $result = array( $error, $id, $data );
        }

        return $result;
    }

    /**
     * Process Call
     * @param $call
     * @return array|null
     */
    private function processCall( $call )
    {
        $id     = property_exists( $call, 'id' ) ? $call->id : null;
        $params = property_exists( $call, 'params' ) ? $call->params : array();
        $result = null;

        try
        {
            // set named parameters
            if ( is_object( $params ) )
            {
                $newParams = array();
                foreach ( $this->reflectionMethods[ $call->method ]->getParameters() as $param )
                {
                    $paramName    = $param->getName();
                    $defaultValue = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                    $newParams[ ] = property_exists( $params, $paramName ) ? $params->$paramName : $defaultValue;
                }

                $params = $newParams;
            }

            // invoke
            $result = $this->reflectionMethods[ $call->method ]->invokeArgs( $this->instance, $params );
        }
        catch ( Exception $e )
        {
            return $this->getError( $e->getCode(), $id, null, $e->getMessage(), $e->getMessage() );
        }
        catch ( \Exception $e )
        {
            return $this->getError( $e->getCode(), $id, null, null, $e->getMessage() );
        }

        if ( !$id )
        {
            return null;
        }

        return array(
            'jsonrpc' => '2.0',
            'result'  => $result,
            'id'      => $id
        );
    }

    /**
     * Create new Instance
     * @param object $instance
     */
    public function __construct( $instance = null )
    {
        if ( get_parent_class( $this ) )
        {
            $this->instance = $this;
        }
        else
        {
            $this->instance                = $instance;
            $this->instance->errorMessages = $this->errorMessages;
        }
    }

    /**
     * Handle Requests
     */
    public function handle()
    {
        $startTime    = microtime( true );
        $request_hdrs = $this->getHeaders( false );
        $request_body = file_get_contents( 'php://input' );

        do
        {
            // check for SMD Discovery request
            if ( $this->showSmd && array_key_exists( 'smd', $_GET ) )
            {
                $this->response[ ] = $this->getServiceMap();
                $this->hasCalls    = true;
                break;
            }

            $error = $this->getRequest();
            if ( $error )
            {
                $this->response[ ] = $this->getError( $error );
                $this->hasCalls    = true;
                break;
            }

            foreach ( $this->calls as $call )
            {
                $error = $this->validateCall( $call );
                if ( $error )
                {
                    $this->response[ ] = $this->getError( $error[ 0 ], $error[ 1 ], $error[ 2 ] );
                    $this->hasCalls    = true;
                }
                else
                {
                    $result = $this->processCall( $call );
                    if ( $result )
                    {
                        $this->response[ ] = $result;
                        $this->hasCalls    = true;
                    }
                }
            }
        }
        while ( false );

        // flush response
        if ( $this->hasCalls )
        {
            if ( !$this->isBatchCall )
            {
                $this->response = reset( $this->response );
            }

            // Set Content Type
            if ( $this->contentType )
            {
                header( 'Content-Type: ' . $this->contentType );
            }

            // Allow Cross Domain Requests
            if ( $this->isXDR )
            {
                header( 'Access-Control-Allow-Origin: *' );
                header( 'Access-Control-Allow-Headers: x-requested-with, content-type' );
            }

            //

            $response = json_encode( $this->response );

            if ( $this->loggerClass )
            {
                $logger              = new $this->loggerClass();
                $logger->serviceName = $this->loggerName;
                $logger->log( $request_hdrs, $request_body, implode( "\n", headers_list() ) . "\n", $response, null, null, microtime( true ) - $startTime, $startTime );
            }

            echo $response;

            $this->resetVars();
        }
    }

    public function getHeaders( $asArray = true )
    {
        if ( function_exists( 'http_get_request_headers' ) )
            $headers = http_get_request_headers();

        elseif ( function_exists( 'getallheaders' ) )
            $headers = getallheaders();

        else
        {
            $headers = array();
            foreach ( $_SERVER as $key => $value )
            {
                if ( substr( $key, 0, 5 ) <> 'HTTP_' )
                {
                    continue;
                }
                $headers[ str_replace( ' ', '-', ucwords( str_replace( '_', ' ', strtolower( substr( $key, 5 ) ) ) ) ) ] = $value;
            }
        }


        $head = array(
            ( isset( $_SERVER[ 'REQUEST_METHOD' ] ) ? ( $_SERVER[ 'REQUEST_METHOD' ] . ' ' ) : '' ) .
                ( isset( $_SERVER[ 'REQUEST_URI' ] ) ? ( $_SERVER[ 'REQUEST_URI' ] . ' ' ) : '' ) .
                ( isset( $_SERVER[ 'SERVER_PROTOCOL' ] ) ? ( $_SERVER[ 'SERVER_PROTOCOL' ] . ' ' ) : '' )
        );

        $headers = array_merge( $head, $headers );

        if ( $asArray )
            return $headers;

        $text = '';
        foreach ( $headers as $name => $value )
            $text .= ( $name ? ( $name . ': ' ) : '' ) . $value . "\n";

        return rtrim( $text, "\n" );
    }

    /**
     * Get Doc Comment
     * @param $comment
     * @return string|null
     */
    private function getDocDescription( $comment )
    {
        $result = null;
        if ( preg_match( '/\*\s+([^@]*)\s+/s', $comment, $matches ) )
        {
            $result = str_replace( '*', "\n", trim( trim( $matches[ 1 ], '*' ) ) );
        }

        return $result;
    }

    /**
     * Get Service Map
     * Maybe not so good realization of auto-discover via doc blocks
     * @return array
     */
    private function getServiceMap()
    {
        $rc     = new \ReflectionClass( $this->instance );
        $result = array(
            'transport'   => 'POST',
            'envelope'    => 'JSON-RPC-2.0',
            'SMDVersion'  => '2.0',
            'contentType' => 'application/json',
            'target'      => !empty( $_SERVER[ 'REQUEST_URI' ] ) ? substr( $_SERVER[ 'REQUEST_URI' ], 0, strpos( $_SERVER[ 'REQUEST_URI' ], '?' ) ) : '',
            'services'    => array(),
            'description' => ''
        );

        // Get Class Description
        if ( $rcDocComment = $this->getDocDescription( $rc->getDocComment() ) )
        {
            $result[ 'description' ] = $rcDocComment;
        }

        foreach ( $rc->getMethods() as $method )
        {
            /** @var \ReflectionMethod $method */
            if ( !$method->isPublic() || in_array( strtolower( $method->getName() ), $this->hiddenMethods ) )
            {
                continue;
            }

            $methodName = $method->getName();
            $docComment = $method->getDocComment();

            $result[ 'services' ][ $methodName ] = array( 'parameters' => array() );

            // set description
            if ( $rmDocComment = $this->getDocDescription( $docComment ) )
            {
                $result[ 'services' ][ $methodName ][ 'description' ] = $rmDocComment;
            }

            // @param\s+([^\s]*)\s+([^\s]*)\s*([^\s\*]*)
            $parsedParams = array();
            if ( preg_match_all( '/@param\s+([^\s]*)\s+([^\s]*)\s*([^\n\*]*)/', $docComment, $matches ) )
            {
                foreach ( $matches[ 2 ] as $number => $name )
                {
                    $type = $matches[ 1 ][ $number ];
                    $desc = $matches[ 3 ][ $number ];
                    $name = trim( $name, '$' );

                    $param                 = array( 'type' => $type, 'description' => $desc );
                    $parsedParams[ $name ] = array_filter( $param );
                }
            }
            ;

            // process params
            foreach ( $method->getParameters() as $parameter )
            {
                $name  = $parameter->getName();
                $param = array( 'name' => $name, 'optional' => $parameter->isDefaultValueAvailable() );
                if ( array_key_exists( $name, $parsedParams ) )
                {
                    $param += $parsedParams[ $name ];
                }

                if ( $param[ 'optional' ] )
                {
                    $param[ 'default' ] = $parameter->getDefaultValue();
                }

                $result[ 'services' ][ $methodName ][ 'parameters' ][ ] = $param;
            }

            // set return type
            if ( preg_match( '/@return\s+([^\s]+)\s*([^\n\*]+)/', $docComment, $matches ) )
            {
                $returns                                          = array( 'type' => $matches[ 1 ], 'description' => trim( $matches[ 2 ] ) );
                $result[ 'services' ][ $methodName ][ 'returns' ] = array_filter( $returns );
            }
        }

        return $result;
    }

    /**
     * Reset Local Class Vars after handle
     */
    private function resetVars()
    {
        $this->response = $this->calls = array();
        $this->hasCalls = $this->isBatchCall = false;
    }

}

?>
