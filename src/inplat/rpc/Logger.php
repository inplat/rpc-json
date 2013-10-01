<?php

namespace inplat\rpc;

/**
 * ServiceLogger
 *
 * for PostgreSQL
 *
 * @author Konstantin Stepanov
 */
class Logger
{

    public $enabled = true;
    public $serviceName;
    public $tableName = 'request_log';

    public function log( $requestHdrs, $requestBody, $responseHdrs, $responseBody, $errorCode, $errorMessage, $time, $startTime )
    {
        if ( !$this->enabled )
            return;

        $requestBody = $this->hidePassword( $requestBody );

        $sql = "
            INSERT INTO $this->tableName
                ( service, request_hdrs, request_body, response_hdrs, response_body, error_code, error_message, \"time\", date, ip )
            VALUES
                ( :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8, to_timestamp( :p9 ), :p10 )";

        $params = array(
            ':p1'  => $this->serviceName,
            ':p2'  => $requestHdrs,
            ':p3'  => $requestBody,
            ':p4'  => $responseHdrs,
            ':p5'  => $responseBody,
            ':p6'  => $errorCode,
            ':p7'  => empty( $errorMessage ) ? null : $errorMessage,
            ':p8'  => str_replace( ',', '.', $time ),
            ':p9'  => str_replace( ',', '.', $startTime ),
            ':p10' => isset( $_SERVER[ 'REMOTE_ADDR' ] ) ? $_SERVER[ 'REMOTE_ADDR' ] : null
        );

        try
        {
            $this->executeSql( $sql, $params );
        }
        catch ( \PDOException $e )
        {
            if ( $e->getCode() === '42P01' )
            {
                $this->createTable();
                $this->executeSql( $sql, $params );
            }
        }
        catch ( \Exception $e )
        {
             //$this->createTable();
        }
    }

    private function hidePassword( $text )
    {
        if ( $json = json_decode( $text, true ) )
        {
            array_walk_recursive( $json, function ( &$item, $key )
            {
                if ( $key === 'password' || $key === 'new_password' ) $item = '******';
            } );

            return json_encode( $json );
        }

        return $text;
    }

    public function createTable()
    {
        $schema = strpos( $this->tableName, '.' ) !== false ? substr( $this->tableName, 0, strpos( $this->tableName, '.' ) ) : 'public';
        $table  = strpos( $this->tableName, '.' ) !== false ? substr( $this->tableName, strpos( $this->tableName, '.' ) + 1 ) : $this->tableName;

        $sql = <<<HEREDOC
CREATE TABLE $schema.$table
(
  id bigserial NOT NULL PRIMARY KEY,
  service text NOT NULL,
  request_hdrs text,
  request_body text,
  response_hdrs text,
  response_body text,
  error_code integer,
  error_message text,
  date timestamp with time zone,
  "time" double precision,
  ip inet
)
WITH (
  OIDS=FALSE
)
HEREDOC;

        $this->executeSql( $sql, array() );

        $sql = <<<HEREDOC
CREATE INDEX ${table}_date_idx
  ON $schema.$table
  USING btree
  (date DESC)
HEREDOC;

        $this->executeSql( $sql, array() );
    }

    protected function executeSql( $sql, $params )
    {
        // Override it
    }

}
