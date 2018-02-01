<?php
/**
 * Created by PhpStorm.
 * User: KaustavGanguli
 * Date: 1/23/2018
 * Time: 12:22 PM
 *
 * CHANGE MODULE NAME IN LINE - 330 IF WORKING WITH OTHER MODULES
 *
 *
 */



//check if PHP version 7 or more is used
$php_version = phpversion();
if($php_version < 7)
    exit("PHP 7 or more is required to run this program");


$config_file = "config.ini";

if (!file_exists($config_file)) { //IF CONFIG FILE DOESN'T EXIST
    exit("config.ini not found"); //EXIT THE PROG WITH ERROR
}

$var = parse_ini_file($config_file,true);


//GET VARIABLES ; HIDE WARNINGS WITH @ IN CASE OF UNDEFINED VARIABLES
$PHP_details = @$var['PHP Details']; //prevent program to print out warning in console with @
$MQ_details = @$var['MQ Details'];
$SUGAR_details = @$var['SUGAR Details'];
$MAPPING = @$var['MAPPING'];

//get individual parameters PHP
$PHP_poll_interval = @$PHP_details['poll interval'];

////get individual parameters MQ
$MQ_hostname = @$MQ_details['hostname'];
$MQ_port = @$MQ_details['port'];
$MQ_userID = @$MQ_details['userID'];
$MQ_password = @$MQ_details['password'];
$MQ_queuemanager = @$MQ_details['queuemanager'];
$MQ_queue = @$MQ_details['queue'];
$MQ_channel = @$MQ_details['channel'];

//get individual parameters SUGAR
$SUGAR_site_URL = @$SUGAR_details['site URL'];
$SUGAR_username = @$SUGAR_details['sugar_username'];
$SUGAR_password = @$SUGAR_details['sugar_password'];


//VALIDATE THE CONFIG FILE BEFORE LOADING VARIABLES
validate_config($var);



$xml_values = array(); //USED TO HOLD THE VALUES OF THE NAME=VALUE PAIR COMING FROM XML

$array_stack = array(); //used for decoupled array values from $xml_values

$field_count = count($MAPPING); //count the number of field for which xpath is defined in mapping section...these fields will have multiple data element



while (1){

//    1. CALL JAVA TO READ QUEUE_DEPTH
//    2. READ QUEUE DEPTH
//    3. IF DEPTH = 0
//          WAIT FOR POLL INTERVAL
//          GOTO STEP 1
//    4. IF DEPTH !=0
//          CALL JAVA TO READ MESSAGE FROM QUEUE & WRITE TO XML
//          REARRANGE DATAELEMENT TO BE INSERTED INTO SUGAR
//          SOAP CALL TO SUGARCRM
//          GOTO STEP 1


    call_JAVA("check_depth"); //READ FROM MQ & WRITE TO queue_depth FILE
    echo "calling java\n";
    $depth = read_depth(); //READ queue_depth FILE
    echo "depth = " . $depth . "\n";
    if($depth == 0){
        sleep($PHP_poll_interval); //WAIT FOR $poll_interval SECONDS
    }
    else{
        //get the first message & write to msg.xml file
        call_JAVA("get_first");
        //
        read_mapping(); //READ msg.xml -> & PUSH XPATH QUERY RESULT INTO $xml_values ARRAY

        //MAKE INDIVIDUAL DATA ELEMENT ARRAY AS {FIELDNAME,datavalue_1}[FIELD_NAME,datavalue_2]
        for($i=0;$i<count($xml_values);$i++){
            $data_count = make_data_array($xml_values[$i]); //CREATE INDIVIDUAL ARRAY ELEMENT OUT OF {FIELDNAME,{DATA_VALUE}}
        }

        //rearrange data array  AS
        // {FIELDNAME1,datavalue_1}[FIELDNAME2,datavalue_1]...{FIELDNAME1,datavalue_2}{FIELDNAME2,datavalue_2}

        $final_array = array(); //REQUIRED TO HOLD THE FINAL RE-ARRANGED ARRAY
        $jump = $data_count;
        for($i=0;$i<$data_count;$i++){

            array_push($final_array,$array_stack[$i]);
            $k=$i;
            for($j=1;$j<$field_count;$j++){

                array_push($final_array,$array_stack[$k+$jump]);
                $k=($k+$jump);
            }
        }

        call_SUGAR($field_count,$data_count);


    }



}




function validate_config(){


    //section headers
    if (!isset($GLOBALS['PHP_details'])){
        exit("PHP Details section not found in config file");
    }
    if (!isset($GLOBALS['MQ_details'])){
        exit("MQ Details section not found in config file");
    }
    if (!isset($GLOBALS['SUGAR_details'])){
        exit("SUGAR Details section not found in config file");
    }
    if (!isset($GLOBALS['MAPPING'])){
        exit("MAPPING section not found in config file");
    }

    //individual parameters for PHP
    if (!isset($GLOBALS['PHP_poll_interval'])){
        exit("poll interval is not found in config file");
    }
    //individual parameters for MQ
    if (!isset($GLOBALS['MQ_hostname'])){
        exit("hostname is not found in config file");
    }
    if (!isset($GLOBALS['MQ_port'])){
        exit("port is not found in config file");
    }
    if (!isset($GLOBALS['MQ_userID'])){
        exit("userID is not found in config file");
    }
    if (!isset($GLOBALS['MQ_password'])){
        exit("password is not found in config file");
    }
    if (!isset($GLOBALS['MQ_queuemanager'])){
        exit("queuemanager is not found in config file");
    }
    if (!isset($GLOBALS['MQ_queue'])){
        exit("queue is not found in config file");
    }
    if (!isset($GLOBALS['MQ_channel'])){
        exit("channel is not found in config file");
    }

    //individual parameters for SUGAR
    if (!isset($GLOBALS['SUGAR_site_URL'])){
        exit("site URL is not found in config file");
    }
    if (!isset($GLOBALS['SUGAR_username'])){
        exit("username is not found in SUGAR Details inside config file");
    }
    if (!isset($GLOBALS['SUGAR_password'])){
        exit("password is not found in SUGAR Details inside config file");
    }

}


function call_JAVA($option) //OPTIONS SHOULD BE "check_depth" or "get_first"
{
    $command = "java -jar jar\MQ_java.jar config.ini " . $option; //PHP CONCATENATION OPERATOR (.)

    //echo $command;

    exec($command);
}

function read_depth(){

    $depth = 0;
    $file_handle = fopen("queue_depth", "r");

    while (!feof($file_handle)) {
        $depth = fgets($file_handle);
    }
    fclose($file_handle);

    return $depth;
}

function read_mapping(){


//$xml=simplexml_load_file("book2.xml");
    $xml=simplexml_load_file("msg.xml");
    $ns=$xml->getNamespaces(true); //recurrsively find all the xmlns value



    $doc = new DOMDocument;
//$doc->load('book2.xml');
    $doc->load('msg.xml');


    $xpath = new DOMXPath($doc);

    foreach($ns as $x => $x_value) {

        $ns_value = $x.":abcd"; //ADD ANY GARBAGE VALUE IN PLACE OF abcd

        $doc->createAttributeNS($x_value,$ns_value); //add all the xmlns value in the root tag so that all subsequent tags can be found


    }

//    echo $doc->saveXML();



//EXAMPLE
//$result = $xpath->query("/soapenv:Envelope/soapenv:Body/tns:SyncContactRequest/eims2:PartyMasterBOD/eims2:DataArea/eims2:PartyMaster/eims2:Source");
//$result = $xpath->query("/A_account/data/account_data/account");

    global $xml_values;

    foreach ($GLOBALS['MAPPING'] as $name => $value){ //DO IT FOR ALL THE MAPPING ELEMENT IN THE CONFIG FILE

        $result = $xpath->query($value); //THIS RETURNS AN ARRAY OF ALL THE OCCURRENCE OF THE XPATH SEARCH; {DATA_VALUES}

        $tmp_array = array($name,$result); //CREATE A 2D ARRAY WITH {FIELDNAME,{DATA_VALUES}}
        array_push($xml_values,$tmp_array); //PUSH THE TEMP ARRAY INTO GLOBAL $xml_values ARRAY

    }




    //return count($xml_values[0]); //return the 1st element to know the number of records returned by xpath query

}

function call_SUGAR($field_count,$data_count){


    $url = $GLOBALS['SUGAR_site_URL']."/service/v4_1/soap.php?wsdl";
    $username = $GLOBALS['SUGAR_username'];
    $password = $GLOBALS['SUGAR_password'];

    echo $url."\n".$username."\n".$password;

    //require NuSOAP
    require_once("./nusoap/lib/nusoap.php");

    //retrieve WSDL
    $client = new nusoap_client($url, 'wsdl');

    //display errors
    $err = $client->getError();
    if ($err)
    {
        echo $err;
        exit();
    }
    else{
        echo "working"."\n";
    }


    //login ----------------------------------------------------

    $login_parameters = array(
        'user_auth' => array(
            'user_name' => $username,
            'password' => md5($password),
            'version' => '1'
        ),
        'application_name' => 'SoapTest',
        'name_value_list' => array(
        ),
    );


    $login_result = $client->call('login', $login_parameters);


    //print_r($login_result);


    //get session id
    $session_id =  $login_result['id'];






    //INSERT DATA

    $data = array();
    global $final_array;

    for($i=0;$i< (($data_count*$field_count)-1) ;$i++){ //as i am reducing the counter as i+j-1

        for($j=0;$j<$field_count;$j++){

            echo "i = ".$i."\nj =".$j."\n";
            array_push($data,$final_array[$i+$j]);
        }
        $i = $i + $j -1;





        $set_entry_parameters = array(
            //session id
            "session" => $session_id,

            //The name of the module from which to retrieve records.
            "module_name" => "Accounts", //CHANGE MODULE NAME HERE IF DIFFERENT MODULE IS IMPORTED

            //Record attributes
            "name_value_list" => $data
        );

//        print_r($set_entry_parameters);

        $set_entry_result = $client->call("set_entry", $set_entry_parameters);


        print_r($set_entry_result);

        $data = array(); //clear the array
    }





}




function make_data_array($obj){


    global $array_stack;


    for($j=0;$j<count($obj[1]);$j++){

        $tmp = array("name"=>$obj[0],"value"=>$obj[1][$j]->nodeValue);
        array_push($array_stack,$tmp);
    }

    return count($obj[1]);
}
