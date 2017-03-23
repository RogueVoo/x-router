<?php
	
	/*
	 * File: xrouter.php
	 * Author: Andrew Saponenko <roguevoo@gmail.com>
	 * Description: Autoloading for x-router
	 */
	 
	function xrouter_autoload( $class )
	{
		$segments = explode( '_', $class );
		$is_xrouter = array_shift($segments);
		
		if( $is_xrouter != 'xrouter' )
			return;

		/*if( count($segments) == 1 )
			array_unshift($segments, 'Main');*/

		$filename = array_pop($segments);
		if( count($segments) )
			$filename = DIRECTORY_SEPARATOR . $filename;
			
		$file = dirname(__FILE__) . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments) . strtolower($filename) . '.php';

		if(file_exists($file)){
			require_once $file;
		}
	}
	
	spl_autoload_register('xrouter_autoload');

	class xrouter
	{
	
		private $_routes = array(
			'get' => array(),
			'post' => array(),
			'delete' => array(),
			'put' => array(),
			'any' =>array()
		);
		
		private $_nf = null;
		
		function xrouter( $opt = array() )
		{
			if( isset($opt['routes']) && is_array($opt['routes']) )
			{
				foreach( $opt['routes'] as $k => $r )
				{
					if( is_array($r) && in_array($k, array('get','post','put','delete') ) )
					{
						foreach( $r as $a )
						{
							if( is_array($a) && count($a) == 2 )
								call_user_func_array( array($this,$k),$a );
						}					
					}
				}
			}
			
			if( isset($opt['handle']) && $opt['handle'] === true ){
				$this->handle();
			}
		}
		
		function & notfound( $arg )
		{
			$this->_nf = $arg;
			return $this;
		}
		
		function & __call( $method, array $params )
		{
			if( in_array( $method, array('get','post','put','delete','any') ) ){
				
				if( count($params) == 1 && is_array($params[0]) ){
					$this->_routes[$method] []= $params[0];
					return $this;
				}
				
				if( count($params) < 2 )
					throw new Exception('Bad params');
					
				if( !isset($this->_routes[$method]) )
					throw new Exception('Unknown method');
					
				$this->_routes[$method] []= $params;
			}
			return $this;
		}
		
		static private $_req = null;
		
		static function request()
		{
			if( self::$_req ){
				return self::$_req;
			}
				
			$req = (object)array(
				'method' => filter_input(INPUT_SERVER,'REQUEST_METHOD'),
				'isAjax' => self::isAjax(),
				'accept' => filter_input(INPUT_SERVER,'HTTP_ACCEPT'),
				'encoding' => filter_input(INPUT_SERVER,'HTTP_ACCEPT_ENCODING'),
				'language' => filter_input(INPUT_SERVER,'HTTP_ACCEPT_LANGUAGE'),
				'cache' => filter_input(INPUT_SERVER,'HTTP_CACHE_CONTROL'),
				'connection' => filter_input(INPUT_SERVER,'HTTP_CONNECTION'),
				'host' => filter_input(INPUT_SERVER,'HTTP_HOST'),
				'user_agent' => filter_input(INPUT_SERVER,'HTTP_USER_AGENT'),
				'port' => filter_input(INPUT_SERVER,'SERVER_PORT'),
				'interface' => filter_input(INPUT_SERVER,'GATEWAY_INTERFACE'),
				'protocol' => filter_input(INPUT_SERVER,'SERVER_PROTOCOL'),
				'query' => filter_input(INPUT_SERVER,'QUERY_STRING'),
				'uri' => filter_input(INPUT_SERVER,'REQUEST_URI'),
				'script' => filter_input(INPUT_SERVER,'SCRIPT_NAME'),
				'self' => filter_input(INPUT_SERVER,'PHP_SELF'),
				'time' => filter_input(INPUT_SERVER,'REQUEST_TIME'),
				'payload' => self::postData()
			);
			
			switch( $req->method )
			{
				case 'GET':
					$req->get = (object)$_GET;
					break;
				
				case 'OPTIONS':
					$req->options = (object)$_GET;
					break;
					
				case 'PUT':
					$req->put = (object)$_POST;
					break;
					
				case 'POST':
					$req->post = (object)$_POST;
					break;
					
				case 'DELETE':
					$req->delete = (object)$_GET;
					break;
			}
			
			self::$_req = $req; 
			
			return self::$_req;
		}
				
		static function responce( $data, array $headers = array() )
		{
			foreach($headers as $h){
				header($h);
			}			
			exit($data);
		}
		
		function handle( $url = null, $method = null )
		{
			$rdata = null;
			
			$req = $this->request();
			
			if( !$url ){
				
				$url = $req->uri; 
			}
			
			//echo ;  
			//exit($url); 
			
			if( !$method ){
				$method = $req->method;
			}
			
			$method = strtolower($method);
			
			if( !isset($this->_routes[$method]) ){
				return $this;
			}
			
			if( $this->_arr($url,$this->_routes[$method],$rdata) ){
				return $rdata;
			}
			
			if( $this->_arr($url,$this->_routes['any'],$rdata) ){
				return $rdata;
			}
			
			if( $method == 'get' ){
				
				if( is_string($this->_nf) )
					$this->redirect( $this->_nf, 404, 'Not Found' );
				
				if( is_callable($this->_nf) )
					$rdata = call_user_func_array($this->_nf, array($this) );				
			}			
			return $rdata;
		}
		
		private function _arr( $url,array $arr, & $rdata )
		{
			$params = array();
			
			foreach( $arr as $route )
			{
				if( !is_array($route) )
					return false;
				
				if( $this->_match($url,$route[0],$params) )
				{
					$a = $route[1];
					if( is_callable($a) )
					{
						$rdata = call_user_func_array( $a, array($this,$params) );
						return ( $rdata !== false );
					}
					return $this->_exec($route[1],$route[2],$params,$rdata);
				}
			}
			return false;
		}
		
		private function _match( $url, $pattern, array & $params )
		{
			$replace = array(
				'*' => '.*',
				'/' => '\/'
			);
			return @preg_match( '/'.str_ireplace( array_keys($replace), array_values($replace), $pattern ).'$/i', $url, $params ) === 1;
		}
		
		private function _exec( $action, $command, array $params = array(), & $rdata = null )
		{
			switch($action)
			{
				case 'redirect':
					$this->redirect( $command, 303, 'See other' );
					break;

				case 'file':
					if( file_exists($command) ){
						$rdata = file_get_contents($command);
						$this->responce( $rdata );
						return true;
					}
					break;

				case 'include':
					if( file_exists($command) )
					{
						$rdata = include($command);
						return true;
					}
					break;
			}
				
			return false;			
		}
		
		static function output( $message, array $headers = array() )
		{
			return self::responce( $message, $headers );
		}
		
		static function outputFile( $filename, array $headers = array() )
		{
			if( file_exists($filename) ){
				return self::responce( file_get_contents($filename), $headers );
			}
		}
		
		static function outputJson( $data, array $headers = array() )
		{
			self::output( json_encode($data), array_merge( $headers, array('Content-type: application/json') ) );
		}
		
		static function outputJsonp( $cb, $data, array $headers = array() )
		{
			self::output( $cb . '(' . json_encode($data) . ')', array_merge( $headers, array('Content-type: application/json') ) );
		}
		
		static function redirect( $url, $code = 307, $message = 'Moved' )
		{
			header("HTTP/1.1 {$code} {$message}");
			header("Location: {$url}");
			exit(1);
		}
		
		static function error( $code, $message = 'Error', $content = null )
		{
			self::responce($content,array(
				"HTTP/1.1 {$code} {$message}"
			));
		}
		
		static function isAjax()
		{
			return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
		}
		
		static function ifAjax( $callback )
		{
			if( !$this->isAjax() )
				return;
						
			if( is_callable($callback) ){
				call_user_func( $callback, $this, self::postData() );
			}
		}
		
		static function postData()
		{
			$post = file_get_contents('php://input');
			$data = null;
			if( $post !== false ){
				if( stripos(filter_input(INPUT_SERVER, 'HTTP_ACCEPT'),'application/json') !== -1 )
					$data = json_decode($post);
			}
			return $data;
		}
	}