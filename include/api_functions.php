<?php
/*
#
# EONAPI
#
# Copyright (c) 2017 AXIANS C&S
# Author: Adrien van den Haak <adrien.vandenhaak@axians.com>
#
*/

// => Modify this key with your own secret at initialization
define("EONAPI_KEY", "€On@piK3Y");


/* API key encryption */
function apiKey( $user_id )
{
    $key = md5(EONAPI_KEY.$user_id);
    $machineid = file_get_contents("/etc/machine-id");

    return hash('sha256', $key.$machineid);
}


/*---General functions--*/
function getParametersNameFunction( $className, $functionName ){
    $reflector = new ReflectionMethod($className, $functionName);
    $params = array(array(),array());
 
    foreach ($reflector->getParameters() as $param) {
        $params[0][] = $param->name;
        if( $param->isDefaultValueAvailable() ){
		$params[1][$param->name] = $param->getDefaultValue(); 
	}
    }
    
    return $params;
}

function getParametersNameCompulsoryFunction( $className, $functionName ){
    $reflector = new ReflectionMethod($className, $functionName);
    $params = array();
    
    foreach ($reflector->getParameters() as $param) {
        if( $param->isDefaultValueAvailable() == false ){
            $params[] = $param->name;
        }
    }
    
    return $params;
}

function has_empty($array) {
    foreach ($array as $value) {
        if ($value == null)
            return true;
    }
    return false;
}

function getUserByUsername( $username ){
    global $database_eonweb;
    $connect = connexionDB($database_eonweb);
    $stmt = $connect->prepare("SELECT U.user_id as user_id,U.user_name as user_name,U.user_passwd as user_passwd,U.user_type as user_type,
    U.user_limitation as user_limitation,R.tab_1 as readonly,R.tab_2 as operator,R.tab_6 as admin
    FROM users as U left join groups as G on U.group_id = G.group_id left join groupright as R on R.group_id=G.group_id
    WHERE U.user_name = :username");

    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $result = $stmt->fetchAll();
    $stmt=null;
    $connect=null;
    
   
    return $result;
}

/*---HTTP Response---*/
function getJsonResponse( $response, $code, $array = null ){
	
	global $app;
	
    $eonapi = \Slim\Slim::VERSION;
    $codeMessage = $response->getMessageForCode( $code );
    $arrayHeader = array("api_version" => $eonapi, "http_code" => $codeMessage);
    $arrayMerge = array_merge( $arrayHeader, $array );

    $jsonResponse = json_encode($arrayMerge, JSON_PRETTY_PRINT);
    $jsonResponseWithHeader = $jsonResponse;

	$app->response->headers->set('Content-Type', 'application/json');
	$app->response->setStatus($codeMessage);
	
    return $jsonResponseWithHeader;
}

function constructResponse( $response, $logs, $authenticationValid = false ){
    //Only if API keys match
    if($authenticationValid == true){
        try {
            $array = array("result" => $logs);
            $result = getJsonResponse($response, "200", $array);
            echo $result;
        }
        catch(PDOException $e) {
            //error_log($e->getMessage(), 3, '/var/tmp/php.log');
            $array = array("error" => $e->getMessage());
            $result = getJsonResponse($response, "400", $array);
            echo $result;
        }
    }
    else{
        $array = array("status" => "unauthorized");
        $result = getJsonResponse($response, "401", $array);
        echo $result;
    }
}

/*---Authorization checks--*/
function verifyAuthenticationByApiKey( $request, $right ){
    $authenticationValid = false;
    
    //Parameters in request
    $paramUsername = $request->get('username');
    $paramApiKey = $request->get('apiKey');
    
    //Do not set $serverApiKey to NULL (bypass risk)
    $serverApiKey = EONAPI_KEY;
    
    $usersql = getUserByUsername( $paramUsername );
    $user_right = $usersql[$right];
    $user_type = $usersql[0]["user_type"];
    
    //IF LOCAL USER AND ADMIN USER (No limitation)
    if( $user_type != "1" && $user_right == "1"){
        //ID of the authenticated user
        $user_id = $usersql[0]["user_id"];
        $serverApiKey = apiKey( $user_id );    
    }
    
    
    //Only if API keys match
    if($paramApiKey == $serverApiKey){
        $authenticationValid = true;
    }

    
    return $authenticationValid;
}

function verifyAuthenticationByPassword( $request ){
    $authenticationValid = false;
    
    //Parameters in request
    $paramUsername = $request->get('username');
    $paramPassword = $request->get('password');
    
    $usersql = getUserByUsername( $paramUsername );
    if($usersql == null){
        return false;
    }
    $user_right = $usersql[0]["readonly"];
    $user_type = $usersql[0]["user_type"];
    
    //IF LOCAL USER AND ADMIN USER (No limitation)
    if( $user_type != "1" && $user_right == "1"){
        $userpasswd = $usersql[0]["user_passwd"];
        $password = md5($paramPassword);
        
        //IF match the hashed password
        if($userpasswd == $password)
            $authenticationValid = true;
    }
    
    return $authenticationValid;
}



/*---Custom calls---*/
function getApiKey(){
    $request = \Slim\Slim::getInstance()->request();
    $response = \Slim\Slim::getInstance()->response();
    
    $authenticationValid = verifyAuthenticationByPassword( $request );
    $usersql=0;
    if( $authenticationValid == TRUE ){
        //ID of the authenticated user
	    $paramUsername = $request->get('username');
        $usersql = getUserByUsername( $paramUsername );
        $user_id = $usersql[0]["user_id"];
        
        $serverApiKey = apiKey( $user_id );
        
        $array = array("EONAPI_KEY" => $serverApiKey);
        $result = getJsonResponse($response, "200", $array);
        echo $result;
    }
    else{
        $array = array("message" => "The username-password credentials of your authentication can not be accepted or the user is not in a group");
        $result = getJsonResponse($response, "401", $array);
        echo $result;
    }  
}

function getAuthenticationStatus(){
	
	$request = \Slim\Slim::getInstance()->request();
    $response = \Slim\Slim::getInstance()->response();
    
    $authenticationValid = verifyAuthenticationByApiKey( $request,"readonly" );    
    if( $authenticationValid == TRUE ){
        $array = array("status" => "authorized");
        $result = getJsonResponse($response, "200", $array);
        echo $result;
    }
    else{
        $array = array("status" => "unauthorized");
        $result = getJsonResponse($response, "401", $array);
        echo $result;
    }
}

?>
