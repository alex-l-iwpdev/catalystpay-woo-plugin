<?php
require_once 'vendor/autoload.php';
use CatalystPay\CatalystPaySDK;

class Catalystpay {
    private $server;
    private $api_channel;
    private $api_token;
    private $test_mode;
    private $errors = [];
    private $last_response = [];
    private $CatalystPaySDK;

    public function __construct($api_channel, $api_token, $test_mode) {
        $this->api_channel = $api_channel;
        $this->api_token = $api_token;
        $this->test_mode = (bool) $test_mode;
        $this->CatalystPaySDK = new CatalystPaySDK(
            $this->api_token,
            $this->api_channel,
            $this->test_mode ? false : true
        );
        $this->server = 'https://'.($this->test_mode ? 'eu-test.' : 'eu-prod.').'oppwa.com/v1';
    }

    public function getServerUrl() {
        return $this->server;
    }

    public function init($data) {

        $data['entityId'] = $this->api_channel;

        $result = json_decode($this->CatalystPaySDK->prepareCheckout($data)->getJson(), true);

        if (!empty($result['id'])) {
            return $result;
        } else {
            return false;
        }
    }

    public function prepareRegisterCheckout($data) {

        $data['entityId'] = $this->api_channel;

		$result = json_decode($this->CatalystPaySDK->prepareRegisterCheckout($data)->getJson(), true);

		if (!empty($result['id'])) {
			return $result;
		} else {									
			return false;
		}
	}


	public function getTransactionInfo($id) {
		$result = json_decode($this->CatalystPaySDK->getPaymentStatus($id)->getJson(), true);

		if (!empty($result['id'])) {
			return $result;
		} else {
			return false;
		}
	}

    public function getRegistrationStatus($id) {
		$result = json_decode($this->CatalystPaySDK->getRegistrationStatus($id)->getJson(), true);

		if (!empty($result['id'])) {
			return $result;
		} else {
			return $result;
		}
	}

    public function sendRegistrationTokenPayment($paymentId, $data) {

		$result = json_decode($this->CatalystPaySDK->sendRegistrationTokenPayment($paymentId, $data)->getJson(), true);

		if (!empty($result['id'])) {
			return $result;
		} else {
			return false;
		}
	}


    public function paymentSubscriptionCard($data) {
		$result = json_decode($this->CatalystPaySDK->paymentSubscriptionCard($data)->getJson(), true);

		if (!empty($result['id'])) {
			return $result;
		} else {
			return false;
		}
	}

    public function hasErrors() {
        return count($this->errors);
    }

    public function getErrors() {
        return $this->errors;
    }

    public function getResponse() {
        return $this->last_response;
    }

    private function execute($method, $uri = '', $data = array()) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->server . $uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization:Bearer '. $this->api_token]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false === $this->test_mode);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ('GET' === $method) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        if ('POST' === $method) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        }

        $responseData = curl_exec($ch);

        if(curl_errno($ch)) {
            $curl_code = curl_errno($ch);
            $constant = get_defined_constants(true);
            $curl_constant = preg_grep('/^CURLE_/', array_flip($constant['curl']));

            $this->errors[] = $curl_constant[$curl_code] . ':' . curl_strerror($curl_code);
        }

        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (($status_code >= 0) && ($status_code < 200)) {
            $this->errors[] = 'Server Not Found (' . $status_code . ')';
        }

        if (($status_code >= 300) && ($status_code < 400)) {
            $this->errors[] = 'Page Redirect (' . $status_code . ')';
        }

        if ($status_code == 400) {
            $this->errors[] = 'Bad Request (' . $status_code . ')';
        }

        if ($status_code == 401) {
            $this->errors[] = 'Unauthorized (' . $status_code . ')';
        }

        if ($status_code == 403) {
            $this->errors[] = 'Forbidden (' . $status_code . ')';
        }

        if (($status_code >= 404) && ($status_code < 500)) {
            $this->errors[] = 'Page not found (' . $status_code . ')';
        }

        if ($status_code >= 500) {
            $this->errors[] = 'Server Error (' . $status_code . ')';
        }

        curl_close($ch);

        if (false !== $responseData) {
            $body = json_decode($responseData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->last_response = $body;
            }
        }

        return $this->last_response;
    }
}