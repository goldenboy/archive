<?php

/*
Copyright (c) 2009, Erik Eldridge. All rights reserved.
Code licensed under the BSD License:
http://github.com/erikeldridge/foxbatexample/blob/master/license.txt
*/
 
session_start();

$filters = array(
    
    //internal params
    'id' => FILTER_SANITIZE_STRING,
    'consumerKey' => FILTER_SANITIZE_STRING,
    
    //fetch hybrid auth
    'action' => FILTER_SANITIZE_STRING,
    
    //exchange req token
    'requestToken' => FILTER_SANITIZE_STRING,
    
    //request params
    'method' => FILTER_SANITIZE_STRING,
    'url' => FILTER_SANITIZE_STRING,
    'params' => FILTER_SANITIZE_STRING
);
$input = filter_var_array($_GET, $filters);

//validate input
if(!isset($input['id'])){
    $data = array('error'=>"iframe id req'd for all requests");    
}

switch($input['action']){
    
    // exchange request token for access token
    // ref: YahooAuthorization::getAccessTokenProxy
    case 'exchangeRequestToken':
    
        //settings
        $oauthIncludePath = '../../yosdk/';
        
        $includePath = get_include_path().PATH_SEPARATOR
            .$oauthIncludePath;
        set_include_path($includePath);
    
        require_once 'Yahoo.inc';
        require_once 'YahooSessionStore.inc';
        require_once 'CustomSessionStore.php';
        require_once '../../netdb/sdk.php';
        
        //fetch key
        require 'secure.inc';
        $storage = new Netdb($netdbUid, $netdbSecret);
        $storageKey = 'yahoo-'.$input['consumerKey'];
        $response = $storage->get($storageKey);
        $value = json_decode($response->value);
        
        // session store interface defined in Yahoo! SDK
        $yahooSdkSessionStore = new CustomSessionStore($storage, $storageKey);

        //use oauth consumer to sign request for access token
        $consumer = new OAuthConsumer($value->consumerKey, $value->consumerSecret);

        //format request token as expected by oauth lib
        $requestToken = new stdclass();
        $requestToken->key = $input['requestToken'];

        //ref: http://step2.googlecode.com/svn/spec/openid_oauth_extension/latest/openid_oauth_extension.html#AuthTokenReq
        $requestToken->secret = '';

        //client defined in Yahoo! SDK
        $client = new OAuthClient($consumer, $requestToken, OAUTH_PARAMS_IN_POST_BODY);

        //$YahooConfig["OAUTH_HOSTNAME"] defined in Yahoo! SDK
        $uri = sprintf("https://%s/oauth/v2/get_token", $YahooConfig["OAUTH_HOSTNAME"]);

        $response = $client->post($uri);

        parse_str($response["responseBody"], $params);

        $now = time();

        $accessToken = new stdclass();

        //note: key is oauth access token.
        //kludge: suspecting php bug - 1st array elem inaccesible by key.
        $accessToken->key = array_shift($params);

        $accessToken->secret = $params["oauth_token_secret"];
        $accessToken->guid = $params["xoauth_yahoo_guid"];

        //note: consumer is the app key
        $accessToken->consumer = $value->consumerKey;

        $accessToken->sessionHandle = $params["oauth_session_handle"];

        // Check to see if the access token ever expires.
        if(array_key_exists("oauth_expires_in", $params)) {
            $accessToken->tokenExpires = $now + $params["oauth_expires_in"];
        }
        else {
            $accessToken->tokenExpires = -1;
        }

        // Check to see if the access session handle ever expires.
        if(array_key_exists("oauth_authorization_expires_in", $params)) {
            $accessToken->handleExpires = $now +
                    $params["oauth_authorization_expires_in"];
        }
        else {
            $accessToken->handleExpires = -1;
        }

        $yahooSdkSessionStore->storeAccessToken($accessToken);
        
        //store req token as sesson id
        $response = $storage->set('sessionId-uid', $input['requestToken']);
        
        if('success' != $response->status){
            //error
        }
        
        $data = array('success'=>'true');
        break;
        
    case 'fetchHybridAuthUrl':
        
        //settings
        $openidIncludePath = '../../openid/openid+oauth/';
        
        //BEGIN: generate openid+oauth redirect url
        
        //format incl path as assumed by openid lib 
        $includePath = get_include_path().PATH_SEPARATOR
            .$openidIncludePath;
        set_include_path($includePath);
        
        require_once 'Auth/OpenID/Consumer.php';
        require_once 'Auth/OpenID/FileStore.php';
        require_once 'Auth/OpenID/SReg.php';
        require_once 'Auth/OpenID/PAPE.php';
        $openidFileStore = new Auth_OpenID_FileStore('/tmp/');
        $openidConsumer =& new Auth_OpenID_Consumer($openidFileStore);

        //this could just as easily be set dynamically
        $openidIdentifier = 'yahoo.com';
        $openidAuthRequest = $openidConsumer->begin($openidIdentifier);

        //Add simple reg support.
        //Note: domains implementing Yahoo! hybrid auth must be whitelisted
        $openidSimpleRegRequest = Auth_OpenID_SRegRequest::build(
            
            // req'd
            array('nickname'), 
            
            // optional
            array('fullname', 'email')
        );
        $openidAuthRequest->addExtension($openidSimpleRegRequest);

        require '../../netdb/sdk.php';
        require 'secure.inc';
        $storage = new Netdb($netdbUid, $netdbSecret);
        $storageKey = 'yahoo-'.$input['consumerKey'];
        $response = $storage->get($storageKey);
        $value = json_decode($response->value);
        
        //url for openid provider log in page
        $openidLoginRedirectUrl = $openidAuthRequest->redirectURL(
            $value->openidRealmUri,
            $value->openidRealmUri.$value->openidReturnToUri
        );

        //add hybrid auth fields
        $additionalFields = array(
            'openid.ns.oauth' => 'http://specs.openid.net/extensions/oauth/1.0',
            'openid.oauth.consumer' => $input['consumerKey']
        );

        $openidLoginRedirectUrl .= '&'.http_build_query($additionalFields);
        //END: generate openid+oauth redirect url
        
        $data = array('url'=>$openidLoginRedirectUrl);
        break;
        
    case 'makeRequest':
    
        require_once '../../netdb/sdk.php';
    
        //fetch key
        require 'secure.inc';
        $storage = new Netdb($netdbUid, $netdbSecret);
        $storageKey = 'yahoo-'.$input['consumerKey'];
        $response = $storage->get($storageKey);
        $value = json_decode($response->value);
    
        //BEGIN: validate request using session id
        
        //fetch session id for claimed user
        $response = $storage->get('sessionId-uid');
            
        //compare it to passed sesion id
        $passedSessionId = $input['requestToken'];
        $storedSessionId = json_decode($response->value);
        if($passedSessionId != $storedSessionId){
            $data = array('status' => 'error', 'details' => 'invalid session id: '.json_decode($response->value));
            break;
        }
        
        //END: validate request using session id
        
        //settings
        $oauthIncludePath = '../../yosdk/';
        
        $includePath = get_include_path().PATH_SEPARATOR
            .$oauthIncludePath;
        set_include_path($includePath);
    
        require_once 'Yahoo.inc';
        require_once 'YahooSessionStore.inc';
        require_once 'CustomSessionStore.php';
        
        // session store interface defined in Yahoo! SDK
        $yahooSdkSessionStore = new CustomSessionStore($storage, $storageKey);

        $yahooSession = YahooSession::requireSession($value->consumerKey, $value->consumerSecret, '71bgIV7k', null, $yahooSdkSessionStore);
        $url = urldecode($input['url']);
        parse_str(urldecode($input['params']), $params);
        $response = $yahooSession->client->get($url, $params);
        
        $data = array('response' => json_decode($response['responseBody']), 'debug' => $validId);
        break;
        
    default:
        //error: invalid service id
        break;
}

//format data for output
$json = urlencode(json_encode($data));
$size = 100;
$chunks = str_split($json, $size);
$total = count($chunks);

//output markup
?>

<? foreach($chunks as $index => $chunk): ?>
    <iframe src="http://test.erikeldridge.com/foxbatexample/client/iframe.html?id=<?= $input['id'] ?>&index=<?= $index ?>&total=<?= $total ?>&chunk=<?= $chunk ?>"></iframe>
<? endforeach ?>