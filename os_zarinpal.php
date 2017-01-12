<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_eshop pay zarinpal plugins
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 20016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die();
require_once JPATH_SITE . '/components/com_eshop/plugins/payment/os_trangell_inputcheck.php';

class os_zarinpal extends os_payment
{

	public function __construct($params) {
        $config = array(
            'type' => 0,
            'show_card_type' => false,
            'show_card_holder_name' => false
        );
        $this->setData('merchant_id',$params->get('merchant_id'));
        
        parent::__construct($params, $config);
	}

	public function processPayment($data) {
		$app	= JFactory::getApplication();
		$Amount = $data['total']/10; // Toman 
		$Description = 'خرید محصول از فروشگاه   '. EshopHelper::getConfigValue('store_owner'); 
		$Email = $data['email']; 
		$Mobile = $data['telephone']; 
		$CallbackURL = JURI::root().'index.php?option=com_eshop&task=checkout.verifyPayment&payment_method=os_zarinpal&id='.$data['order_id']; 
			
		try {
			 $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 	
			//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local

			$result = $client->PaymentRequest(
				[
				'MerchantID' => $this->data['merchant_id'],
				'Amount' => $Amount,
				'Description' => $Description,
				'Email' => $Email,
				'Mobile' => $data['telephone'],
				'CallbackURL' => $CallbackURL,
				]
			);
			
			$resultStatus = abs($result->Status); 
			if ($resultStatus == 100) {
			
			Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority); 
			//Header('Location: https://sandbox.zarinpal.com/pg/StartPay/'.$result->Authority); // for local/
			} else {
				echo'ERR: '.$resultStatus;
			}
		}
		catch(\SoapFault $e) {
			$msg= $this->getGateMsg('error'); 
			$app	= JFactory::getApplication();
			$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
		
	}

	protected function validate($id) {
		$app	= JFactory::getApplication();		
		$allData = EshopHelper::getOrder(intval($id)); //get all data
		//$mobile = $allData['telephone'];
		$jinput = JFactory::getApplication()->input;
		$Authority = $jinput->get->get('Authority', '0', 'INT');
		$status = $jinput->get->get('Status', '', 'STRING');
		
		$this->logGatewayData(' OrderID: ' . $id . 'Authority:' . $Authority . 'Status:'.$status. 'OrderTime:'.time() );
		
		if (checkHack::checkString($status)){

			if ($status == 'OK') {
				try {
				    $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 
					//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local

					$result = $client->PaymentVerification(
						[
							'MerchantID' => $this->data['merchant_id'],
							'Authority' => $Authority,
							'Amount' => round($allData->total/10,4),
						]
					);
					$resultStatus = abs($result->Status); 
					if ($resultStatus == 100) {
						$this->onPaymentSuccess($id, $result->RefID); 
						$msg= $this->getGateMsg($resultStatus); 
						$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=complete',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>'.'<h3>'. $result->RefID .'شماره پیگری ' .'</h3>' , $msgType='Message'); 
						return true;
					} 
					else {
						$msg= $this->getGateMsg($resultStatus); 
						$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
						return false;
					}
				}
				catch(\SoapFault $e) {
					$msg= $this->getGateMsg('error'); 
					$app	= JFactory::getApplication();
					$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
					$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
					return false;
				}
		}
		else {
			$msg= $this->getGateMsg(intval(17)); 
			$app	= JFactory::getApplication();
			$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
			return false;	
		}
	}
	else {
		$msg= $this->getGateMsg('hck2'); 
		$app	= JFactory::getApplication();
		$link = JRoute::_(JUri::root().'index.php?option=com_eshop&view=checkout&layout=cancel',false);
		$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
		return false;	
	}
	
}

	public function verifyPayment() {
		$jinput = JFactory::getApplication()->input;
		$id = $jinput->get->get('id', '0', 'INT');
		$row = JTable::getInstance('Eshop', 'Order');
		$row->load($id);
		if ($row->order_status_id == EshopHelper::getConfigValue('complete_status_id'))
				return false;
				
		$this->validate($id);
	}

	public function getGateMsg ($msgId) {
		switch($msgId){
			case	11: $out =  'شماره کارت نامعتبر است';break;
			case	12: $out =  'موجودي کافي نيست';break;
			case	13: $out =  'رمز نادرست است';break;
			case	14: $out =  'تعداد دفعات وارد کردن رمز بيش از حد مجاز است';break;
			case	15: $out =   'کارت نامعتبر است';break;
			case	17: $out =   'کاربر از انجام تراکنش منصرف شده است';break;
			case	18: $out =   'تاريخ انقضاي کارت گذشته است';break;
			case	21: $out =   'پذيرنده نامعتبر است';break;
			case	22: $out =   'ترمينال مجوز ارايه سرويس درخواستي را ندارد';break;
			case	23: $out =   'خطاي امنيتي رخ داده است';break;
			case	24: $out =   'اطلاعات کاربري پذيرنده نامعتبر است';break;
			case	25: $out =   'مبلغ نامعتبر است';break;
			case	31: $out =  'پاسخ نامعتبر است';break;
			case	32: $out =   'فرمت اطلاعات وارد شده صحيح نمي باشد';break;
			case	33: $out =   'حساب نامعتبر است';break;
			case	34: $out =   'خطاي سيستمي';break;
			case	35: $out =   'تاريخ نامعتبر است';break;
			case	41: $out =   'شماره درخواست تکراري است';break;
			case	42: $out =   'تراکنش Sale يافت نشد';break;
			case	43: $out =   'قبلا درخواست Verify داده شده است';break;
			case	44: $out =   'درخواست Verify يافت نشد';break;
			case	45: $out =   'تراکنش Settle شده است';break;
			case	46: $out =   'تراکنش Settle نشده است';break;
			case	47: $out =   'تراکنش Settle يافت نشد';break;
			case	48: $out =   'تراکنش Reverse شده است';break;
			case	49: $out =   'تراکنش Refund يافت نشد';break;
			case	51: $out =   'تراکنش تکراري است';break;
			case	52: $out =   'سرويس درخواستي موجود نمي باشد';break;
			case	54: $out =   'تراکنش مرجع موجود نيست';break;
			case	55: $out =   'تراکنش نامعتبر است';break;
			case	61: $out =   'خطا در واريز';break;
			case	100: $out =   'تراکنش با موفقيت انجام شد.';break;
			case	111: $out =   'صادر کننده کارت نامعتبر است';break;
			case	112: $out =   'خطاي سوئيچ صادر کننده کارت';break;
			case	113: $out =   'پاسخي از صادر کننده کارت دريافت نشد';break;
			case	114: $out =   'دارنده کارت مجاز به انجام اين تراکنش نيست';break;
			case	412: $out =   'شناسه قبض نادرست است';break;
			case	413: $out =   'شناسه پرداخت نادرست است';break;
			case	414: $out =   'سازمان صادر کننده قبض نامعتبر است';break;
			case	415: $out =   'زمان جلسه کاري به پايان رسيده است';break;
			case	416: $out =   'خطا در ثبت اطلاعات';break;
			case	417: $out =   'شناسه پرداخت کننده نامعتبر است';break;
			case	418: $out =   'اشکال در تعريف اطلاعات مشتري';break;
			case	419: $out =   'تعداد دفعات ورود اطلاعات از حد مجاز گذشته است';break;
			case	421: $out =   'IP نامعتبر است';break;
			case	500: $out =   'کاربر به صفحه زرین پال رفته ولي هنوز بر نگشته است';break;
			case	'error': $out ='خطا غیر منتظره رخ داده است';break;
			case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
		}
		return $out;
	}
}
