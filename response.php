<?php
session_start();
/*Note : After completing transaction process it is recommended to make an enquiry call with PayU to validate the response received and then save the response to DB or display it on UI*/

$postdata = $_POST;
$msg = '';
$salt = $_SESSION['salt']; //Salt already saved in session during initial request.

/* Response received from Payment Gateway at this page.

It is absolutely mandatory that the hash (or checksum) is computed again after you receive response from PayU and compare it with request and post back parameters. This will protect you from any tampering by the user and help in ensuring a safe and secure transaction experience. It is mandate that you secure your integration with PayU by implementing Verify webservice and Webhook/callback as a secondary confirmation of transaction response.

Process response parameters to generate Hash signature and compare with Hash sent by payment gateway 
to verify response content. Response may contain additional charges parameter so depending on that 
two order of strings are used in this kit.

Hash string without Additional Charges -
hash = sha512(SALT|status||||||udf5|||||email|firstname|productinfo|amount|txnid|key)

With additional charges - 
hash = sha512(additionalCharges|SALT|status||||||udf5|||||email|firstname|productinfo|amount|txnid|key)

*/
if (isset($postdata ['key'])) {
	$key				=   $postdata['key'];
	$txnid 				= 	$postdata['txnid'];
    $amount      		= 	$postdata['amount'];
	$productInfo  		= 	$postdata['productinfo'];
	$firstname    		= 	$postdata['firstname'];
	$email        		=	$postdata['email'];
	$udf5				=   $postdata['udf5'];	
	$status				= 	$postdata['status'];
	$resphash			= 	$postdata['hash'];
	//Calculate response hash to verify	
	$keyString 	  		=  	$key.'|'.$txnid.'|'.$amount.'|'.$productInfo.'|'.$firstname.'|'.$email.'|||||'.$udf5.'|||||';
	$keyArray 	  		= 	explode("|",$keyString);
	$reverseKeyArray 	= 	array_reverse($keyArray);
	$reverseKeyString	=	implode("|",$reverseKeyArray);
	$CalcHashString 	= 	strtolower(hash('sha512', $salt.'|'.$status.'|'.$reverseKeyString)); //hash without additionalcharges
	
	//check for presence of additionalcharges parameter in response.
	$additionalCharges  = 	"";
	
	If (isset($postdata["additionalCharges"])) {
       $additionalCharges=$postdata["additionalCharges"];
	   //hash with additionalcharges
	   $CalcHashString 	= 	strtolower(hash('sha512', $additionalCharges.'|'.$salt.'|'.$status.'|'.$reverseKeyString));
	}
	//Comapre status and hash. Hash verification is mandatory.
	if ($status == 'success'  && $resphash == $CalcHashString) {
		$msg = "Transaction Successful, Hash Verified...<br />";
		//Do success order processing here...
		//Additional step - Use verify payment api to double check payment.
		if(verifyPayment($key,$salt,$txnid,$status))
			$msg = "Transaction Successful, Hash Verified...Payment Verified...";
		else
			$msg = "Transaction Successful, Hash Verified...Payment Verification failed...";
	}
	else {
		//tampered or failed
		$msg = "Payment failed for Hash not verified...";
	} 
}
else exit(0);


//This function is used to double check payment
function verifyPayment($key,$salt,$txnid,$status)
{
	$command = "verify_payment"; //mandatory parameter
	
	$hash_str = $key  . '|' . $command . '|' . $txnid . '|' . $salt ;
	$hash = strtolower(hash('sha512', $hash_str)); //generate hash for verify payment request

    $r = array('key' => $key , 'hash' =>$hash , 'var1' => $txnid, 'command' => $command);
	    
    $qs= http_build_query($r);
	//for production
    //$wsUrl = "https://info.payu.in/merchant/postservice.php?form=2";
   
	//for test
	$wsUrl = "https://test.payu.in/merchant/postservice.php?form=2";
	
	try 
	{		
		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, $wsUrl);
		curl_setopt($c, CURLOPT_POST, 1);
		curl_setopt($c, CURLOPT_POSTFIELDS, $qs);
		curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_SSLVERSION, 6); //TLS 1.2 mandatory
		curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
		$o = curl_exec($c);
		if (curl_errno($c)) {
			$sad = curl_error($c);
			throw new Exception($sad);
		}
		curl_close($c);
		
		/*
		Here is json response example -
		
		{"status":1,
		"msg":"1 out of 1 Transactions Fetched Successfully",
		"transaction_details":</strong>
		{	
			"Txn72738624":
			{
				"mihpayid":"403993715519726325",
				"request_id":"",
				"bank_ref_num":"670272",
				"amt":"6.17",
				"transaction_amount":"6.00",
				"txnid":"Txn72738624",
				"additional_charges":"0.17",
				"productinfo":"P01 P02",
				"firstname":"Viatechs",
				"bankcode":"CC",
				"udf1":null,
				"udf3":null,
				"udf4":null,
				"udf5":"PayUBiz_PHP7_Kit",
				"field2":"179782",
				"field9":" Verification of Secure Hash Failed: E700 -- Approved -- Transaction Successful -- Unable to be determined--E000",
				"error_code":"E000",
				"addedon":"2019-08-09 14:07:25",
				"payment_source":"payu",
				"card_type":"MAST",
				"error_Message":"NO ERROR",
				"net_amount_debit":6.17,
				"disc":"0.00",
				"mode":"CC",
				"PG_TYPE":"AXISPG",
				"card_no":"512345XXXXXX2346",
				"name_on_card":"Test Owenr",
				"udf2":null,
				"status":"success",
				"unmappedstatus":"captured",
				"Merchant_UTR":null,
				"Settled_At":"0000-00-00 00:00:00"
			}
		}
		}
		
		Decode the Json response and retrieve "transaction_details" 
		Then retrieve {txnid} part. This is dynamic as per txnid sent in var1.
		Then check for mihpayid and status.
		
		*/
		$response = json_decode($o,true);
		
		if(isset($response['status']))
		{
			// response is in Json format. Use the transaction_detailspart for status
			$response = $response['transaction_details'];
			$response = $response[$txnid];
			
			if($response['status'] == $status) //payment response status and verify status matched
				return true;
			else
				return false;
		}
		else {
			return false;
		}
	}
	catch (Exception $e){
		return false;	
	}
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>PayUBiz PHP7 Kit</title>
</head>
<style type="text/css">
	.main {
		margin-left:30px;
		font-family:Verdana, Geneva, sans-serif, serif;
	}
	.text {
		float:left;
		width:180px;
	}
	.dv {
		margin-bottom:5px;
	}
	.info{
		color:#536152;	
	}
	td{
		border-style:solid; 
		border-width:1px; 
	}
</style>
<body>
<div class="main">
	<div>
    	<img src="images/logo.png" />
    </div>
    <div>
    	<h3>PHP7 PayUBiz Response</h3>
    </div>
	<!-- See below for all response parameters and their brief descriptions //-->    
    
    <div class="dv">
    <span class="text"><label>Transaction/Order ID:</label></span>
    <span><?php echo $txnid; ?></span>
    </div>
    
    <div class="dv">
    <span class="text"><label>Amount:</label></span>
    <span><?php echo $amount; ?></span>    
    </div>
    
    <div class="dv">
    <span class="text"><label>Product Info:</label></span>
    <span><?php echo $productInfo; ?></span>
    </div>
    
    <div class="dv">
    <span class="text"><label>First Name:</label></span>
    <span><?php echo $firstname; ?></span>
    </div>
    
    <div class="dv">
    <span class="text"><label>Email ID:</label></span>
    <span><?php echo $email; ?></span>
    </div>
	
	<div class="dv">
    <span class="text"><label>Additional Charges:</label></span>
    <span><?php echo $additionalCharges; ?></span>
    </div>
    
    <div class="dv">
    <span class="text"><label>Hash:</label></span>
    <span><?php echo $resphash; ?></span>
    </div>
    
    <div class="dv">
    <span class="text"><label>Transaction Status:</label></span>
    <span><?php echo $status; ?></span>
    </div>
    
    <div class="dv">
    <span class="text"><label>Message:</label></span>
    <span><strong><?php echo $msg; ?></strong></span>
    </div>
    
    <br />
    <br />
    <div class="dv">
    <span class="text"><label><a href="./">New Order</a></label></span>    
    </div>
    
	<!-- Response Parameters 
	For more details please refer PDF...
	
	  1
      mihpayid
      It is a unique reference number created for each transaction at PayU’s end. For every new transaction request that hits PayU’s server (coming from any of our merchants), a unique reference ID is created and it is known as <strong>mihpayid (or PayU ID)
    
    
      2
      mode
      This parameter describes the payment category by which the transaction was completed/attempted by the customer. The values are mentioned below:</p>
        
    
      3
      status
      This parameter gives the status of the transaction. Hence, the value of this parameter depends on whether the transaction was successful or not. You must map the order status using this parameter only. The values are as below:
        If the transaction is successful, the value of ‘status’ parameter would be ‘success’.
		The value of ‘status’ as ‘failure’ or ‘pending’ must be treated as a failed transaction only.
    
    
      4
      key
      This parameter would contain the merchant key for the merchant’s account at PayU. It would be the same as the key used while the transaction request is being posted from merchant’s end to PayU.
    
    
      5
      txnid
      This parameter would contain the transaction ID value posted by the merchant during the transaction request.
    
    
      6
      amount
      This parameter would contain the original amount which was sent in the transaction request by the merchant.
    
      7
      discount
      This parameter would contain the discount given to user - based on the type of offer applied by the merchant.
    
    
      8
      offer
      This parameter would contain the offer key which was sent in the transaction request by the merchant.
    
    
      9
      productinfo
      This parameter would contain the same value of productinfo which was sent in the transaction request from merchant’s end to PayU
    
    
      10
      firstname
      This parameter would contain the same value of firstname which was sent in the transaction request from merchant’s end to PayU
    
    
      11
      lastname
      This parameter would contain the same value of lastname which was sent in the transaction request from merchant’s end to PayU
    
    
      12
      address1
      This parameter would contain the same value of address1 which was sent in the transaction request from merchant’s end to PayU
    
    
      13
      address2
      This parameter would contain the same value of address2 which was sent in the transaction request from merchant’s end to PayU
    
    
      14
      city
      This parameter would contain the same value of city which was sent in the transaction request from merchant’s end to PayU
    
    
      15
      state
      This parameter would contain the same value of state which was sent in the transaction request from merchant’s end to PayU
    
    
      16
      country
      This parameter would contain the same value of country which was sent in the transaction request from merchant’s end to PayU
    
    
      17
      zipcode
      This parameter would contain the same value of zipcode which was sent in the transaction request from merchant’s end to PayU
    
    
      18
      email
      This parameter would contain the same value of email which was sent in the transaction request from merchant’s end to PayU
    
    
      19
      phone
      This parameter would contain the same value of phone which was sent in the transaction request from merchant’s end to PayU
    
    
      20
      udf1
      This parameter would contain the same value of udf1 which was sent in the transaction request from merchant’s end to PayU
    
    
      21
      udf2
      This parameter would contain the same value of udf2 which was sent in the transaction request from merchant’s end to PayU
    
    
      22
      udf3
      This parameter would contain the same value of udf3 which was sent in the transaction request from merchant’s end to PayU
    
    
      23
      udf4
      This parameter would contain the same value of udf4 which was sent in the transaction request from merchant’s end to PayU
    
    
      24
      udf5
      This parameter would contain the same value of udf5 which was sent in the transaction request from merchant’s end to PayU
    
      25
      hash
      This parameter is absolutely crucial and is similar to the hash parameter used in the transaction request send by the merchant to PayU. PayU calculates the hash using a string of other parameters and returns to the merchant. The merchant must verify the hash and then only mark a transaction as success/failure. This is to make sure that the transaction hasn’t been tampered with. The calculation is as below:
      
        sha512(SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
        The handling of udf1 – udf5 parameters remains similar to the hash calculation when the merchant sends it in the transaction request to PayU. If any of the udf (udf1-udf5) was posted in the transaction request, it must be taken in hash calculation also.
        If none of the udf parameters were posted in the transaction request, they should be left empty in the hash calculation too.
    
    
      26
      error
      For the failed transactions, this parameter provides the reason of failure. Please note that the reason of failure depends upon the error codes provided by different banks and hence the detailing of error reason may differ from one transaction to another. The merchant can use this parameter to retrieve the reason of failure for a particular transaction.
    
    
      27
      bankcode
      This parameter would contain the code indicating the payment option used for the transaction. For example, in Debit Card mode, there are different options like Visa Debit Card, Mastercard, Maestro etc. For each option, a unique bankcode exists. It would be returned in this bankcode parameter. For example, Visa Debit Card – VISA, Master Debit Card – MAST.
    
    
      28
      PG_TYPE
      This parameter gives information on the payment gateway used for the transaction. For example, if SBI PG was used, it would contain the value SBIPG. If SBI Netbanking was used for the transaction, the value of PG_TYPE would be SBINB. Similarly, it would have a unique value for all different type of payment gateways.
    
    
      29
      bank_ref_num
      For each successful transaction – this parameter would contain the bank reference number generated by the bank.
    
    
      30
      shipping_firstname
      This parameter would contain the same value of shipping_firstname which was sent in the transaction request from merchant’s end to PayU
    
      31
      shipping_lastname
      This parameter would contain the same value of shipping_lastname which was sent in the transaction request from merchant’s end to PayU
    
    
      32
      shipping_address1
      This parameter would contain the same value of shipping_address1 which was sent in the transaction request from merchant’s end to PayU
    
    
      33
      shipping_address2
      This parameter would contain the same value of shipping_address2 which was sent in the transaction request from merchant’s end to PayU
    
    
      34
      shipping_city
      This parameter would contain the same value of shipping_city which was sent in the transaction request from merchant’s end to PayU
    
    
      35
      shipping_state
      This parameter would contain the same value of shipping_state which was sent in the transaction request from merchant’s end to PayU
    
    
      36
      shipping_country
      This parameter would contain the same value of shipping_country which was sent in the transaction request from merchant’s end to PayU
    
    
      37
      shipping_zipcode
      This parameter would contain the same value of shipping_zipcode which was sent in the transaction request from merchant’s end to PayU
    
    
      38
      shipping_phone
      This parameter would contain the same value of shipping_phone which was sent in the transaction request from merchant’s end to PayU
    
    
      39
      unmappedstatus
      This parameter contains the status of a transaction as per the internal database of PayU. PayU’s system has several intermediate status which are used for tracking various activities internal to the system. Hence, this status contains intermediate states of a transaction also - and hence is known as unmappedstatus.
        For example: dropped/bounced/captured/auth/failed/usercancelled/pending
    //-->
</div>
</body>
</html>
	