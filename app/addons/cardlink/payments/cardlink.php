<?php
session_start();

/**
 * @var array $processor_data
 * @var array $order_info
 * @var string $mode
 */

defined('BOOTSTRAP') or die('Access denied');

if (defined('PAYMENT_NOTIFICATION')) {
	$pp_response = array();
	$pp_response['order_status'] = 'F';
	$pp_response['reason_text'] = __('text_transaction_declined');
	$order_id = !empty($_REQUEST['order_id']) ? (int)$_REQUEST['order_id'] : 0;

	if ($mode == 'success' && !empty($_REQUEST['order_id'])) {
		$order_info = fn_get_order_info($order_id);

		if (empty($processor_data)) {
			$processor_data = fn_get_processor_data($order_info['payment_id']);
		}

		$post_data = array();
		$post_data_values = array(
			'version',
			'mid',
			'orderid',
			'status',
			'orderAmount',
			'currency',
			'paymentTotal',
			'message',
			'riskScore',
			'payMethod',
			'txId',
			'paymentRef',
			'extToken',
			'extTokenPanEnd',
			'extTokenExp',
		);

		foreach ($post_data_values as $post_data_value) {
			if (isset($_REQUEST[$post_data_value])) {
				$post_data[] = $_REQUEST[$post_data_value];
			}
		}


		$form_secret = $processor_data['processor_params']['shared_secret'];
		$form_data = iconv('utf-8', 'utf-8//IGNORE', implode("", $post_data)) . $form_secret;
		$digest = base64_encode(hash('sha256', $form_data, true));


		if ($_REQUEST['digest'] === $digest) {
			if ($_REQUEST['status'] == 'CAPTURED' || $_REQUEST['status'] == 'AUTHORIZED') {


				if($order_info['user_id']!=0){
					fn_login_user($order_info['user_id']);
				}


				$pp_response['order_status'] = 'P';
				$pp_response['reason_text'] = __('transaction_approved');
				$pp_response['transaction_id'] = $_REQUEST['paymentRef'];

				$payMethod = $_REQUEST['payMethod'];

				$extToken = isset($_REQUEST['extToken']) ? filter_var($_REQUEST['extToken'], FILTER_SANITIZE_STRING) : '';
				$extTokenPanEnd = isset($_REQUEST['extTokenPanEnd']) ? filter_var($_REQUEST['extTokenPanEnd'], FILTER_SANITIZE_STRING) : '';
				$extTokenExp = isset($_REQUEST['extTokenExp']) ? $_REQUEST['extTokenExp'] : '';
				$extTokenExpYear = substr($extTokenExp, 0, 4);
				$extTokenExpMonth = substr($extTokenExp, 4, 2);


				$card_exist = db_get_field("SELECT card_id FROM ?:cardlink_cards WHERE card_type=?s AND last_four=?s AND expiry_year=?s AND expiry_month", $payMethod, $extTokenPanEnd, $extTokenExpYear, $extTokenExpMonth);

				if ($extToken && !$card_exist) {
					// Build the token
					$token = [
						'token'        => $extToken,
						'last_four'    => $extTokenPanEnd,
						'expiry_year'  => $extTokenExpYear,
						'expiry_month' => $extTokenExpMonth,
						'card_type'    => $payMethod,
						'user_id'      => $order_info['user_id']
					];
					db_query("INSERT INTO ?:cardlink_cards ?e", $token);
				}
				//				fn_print_die($card_exist,$token,$_REQUEST);
			}elseif($_REQUEST['status']=='CANCELED'){

				$pp_response['order_status'] = 'I';
				$pp_response['reason_text'] = __("text_transaction_cancelled");

			}elseif($_REQUEST['status']=='REFUSED'){

				$pp_response['order_status'] = 'D';
				$pp_response['reason_text'] = __("text_transaction_declined");

			}elseif($_REQUEST['status']=='ERROR'){

				$pp_response['order_status'] = 'F';
				$pp_response['reason_text'] = __("cardlink.unexpected_error");

			}elseif($_REQUEST['status']=='AUTHORISED-EXPIRED'){

				$pp_response['order_status'] = 'F';
				$pp_response['reason_text'] = __("cardlink.preauth_expired");

			}elseif($_REQUEST['status']=='REVERSED'){

				$pp_response['order_status'] = 'F';
				$pp_response['reason_text'] = __("cardlink.preauth_canceled");

			}else{
				$pp_response['order_status'] = 'F';
				$pp_response['reason_text'] = __("cardlink.unknown_error");
			}
		} else {
			$pp_response['order_status'] = 'F';
			$pp_response['reason_text'] = __("cardlink.digest_wrong");
		}


	}

	if (fn_check_payment_script('cardlink.php', $order_id)) {
		fn_finish_payment($order_id, $pp_response);
		fn_order_placement_routines('route', $order_id);
	}

} else {

	$payment_url = '';
	if ($processor_data['processor_params']['mode'] == 'test') {
		switch ($processor_data['processor_params']['acquirer']) {
			case 0 :
				$payment_url = "https://ecommerce-test.cardlink.gr/vpos/shophandlermpi";
				break;
			case 1 :
				$payment_url = "https://alphaecommerce-test.cardlink.gr/vpos/shophandlermpi";
				break;
			case 2 :
				$payment_url = "https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi";
				break;
		}
	} else {
		switch ($processor_data['processor_params']['acquirer']) {
			case 0 :
				$payment_url = "https://ecommerce.cardlink.gr/vpos/shophandlermpi";
				break;
			case 1 :
				$payment_url = "https://www.alphaecommerce.gr/vpos/shophandlermpi";
				break;
			case 2 :
				$payment_url = "https://vpos.eurocommerce.gr/vpos/shophandlermpi";
				break;
		}
	}


	if (preg_match("#\_#", $order_id)) {
		list($order_id) = fn_update_order($_SESSION['cart'], 0);
	}

	$version = 2;
	$trType = $processor_data['processor_params']['transaction_type'] == 'yes' ? 2 : 1;
	$country = $order_info['b_country'];
	$state_code = $order_info['b_state'];

	$amount = fn_format_price($order_info['total'], $processor_data['processor_params']['currency']);
	$confirm_url = fn_url("payment_notification.success?payment=cardlink&order_id=$order_id", AREA, 'current');
	$cancel_url = fn_url("payment_notification.fail?payment=cardlink&order_id=$order_id", AREA, 'current');

	$installments = '';

	if (isset($_SESSION['cart']['installments']) && $_SESSION['cart']['installments'] > 1) {
		$installments = $_SESSION['cart']['installments'];
	}

	if (isset($_REQUEST['installments']) && $_REQUEST['installments'] > 1) {
		$installments = $_REQUEST['installments'];
	}

    $installments = $installments <=60 ? $installments : '';

	$offset = ($installments > 0) ? 0 : '';

	$store_card = $_REQUEST['store_card'] ? true : false;
	$selected_card = $_REQUEST['cardlink_payment_gateway-card'] ? $_REQUEST['cardlink_payment_gateway-card'] : false;


	$post_data = array(
		'version'     => $version,
		'mid'         => $processor_data['processor_params']['merchant_id'],
		'lang'        => CART_LANGUAGE,
		'orderid'     => $order_id . 'at' . date('Ymdhisu'),
		'orderDesc'   => '#' . $order_id,
		'orderAmount' => $amount,
		'currency'    => $processor_data['processor_params']['currency'],
		'payerEmail'  => $order_info['email'],

		'billCountry'          => $country,
		'billState'            => $state_code,
		'billZip'              => $order_info['b_zipcode'],
		'billCity'             => $order_info['b_city'],
		'billAddress'          => $order_info['b_address'],
		'trType'               => $trType,
		'cssUrl'               => $processor_data['processor_params']['css_url'],
		'extInstallmentoffset' => $offset,
		'extInstallmentperiod' => $installments,
		'confirmUrl'           => $confirm_url,
		'cancelUrl'            => $cancel_url
	);


	if ($_SESSION['auth']['user_id'] && $processor_data['processor_params']['tokenization'] == 'yes') {
		if ($store_card) {
			$post_data['extTokenOptions'] = 100;
		} else {
			if ($selected_card) {
				$post_data['extTokenOptions'] = 110;
				$post_data['extToken'] = $selected_card;
			}
		}
	}


	$form_secret = $processor_data['processor_params']['shared_secret'];
	$form_data = iconv('utf-8', 'utf-8//IGNORE', implode("", $post_data)) . $form_secret;
	$post_data['digest'] = base64_encode(hash('sha256', $form_data, true));

	fn_create_payment_form($payment_url, $post_data, 'Cardlink', false);

}
exit;
