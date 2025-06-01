<?php

class MisPayController
{
    private $SANDBOX_API_URL = 'https://api.sandbox.mispay.co/v1/api/';
    private $PROD_API_URL = 'https://api.mispay.co/v1/api/';
    private $API_URL;
    private $APP_ID;
    private $APP_SECRET;
    private $ACCESS_TOKEN;

    public function __construct($isSandbox, $appId, $appSecret)
    {
        $this->API_URL = $isSandbox ? $this->SANDBOX_API_URL : $this->PROD_API_URL;
        $this->APP_ID = $appId;
        $this->APP_SECRET = $appSecret;
    }

    function get_access_token()
    {
        $api_url = $this->API_URL . 'token';

        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'x-app-id' => $this->APP_ID,
                'x-app-secret' => $this->APP_SECRET,
                'accept' => 'application/json',
            ),
        ));


        if (is_wp_error($response)) {
            return false;
        }


        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($data['status'] !== 'success') {
            return false;
        }

        $encryptedAccessToken = $data['result']['token'];
        $decryptedToken = $this->decrypt($encryptedAccessToken);

        if (!empty($decryptedToken['token'])) {
            $this->ACCESS_TOKEN = $decryptedToken['token'];
            return $decryptedToken['token'];
        } else {
            return false;
        }
    }

    function start_checkout($orderId, $totalAmount)
    {
        $this->get_access_token();

        $api_url = $this->API_URL . 'start-checkout';

        $payload = json_encode(array(
            'orderId' => "$orderId",
            'purchaseAmount' => (float) $totalAmount,
            'purchaseCurrency' => 'SAR',
            'version' => 'v1.1'
        ));

        $response = wp_remote_post($api_url, array(
            'body'    => $payload,
            'headers' => array(
                'x-app-id' => $this->APP_ID,
                'Authorization' => 'Bearer ' . $this->ACCESS_TOKEN,
                'Content-Type' => 'application/json',
                'accept' => 'application/json',
            ),
        ));
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($data['status'] !== 'success') {
            return false;
        }

        return $data['result']['url'];
    }

    function end_checkout($transactionId)
    {
        $this->get_access_token();

        $api_url = $this->API_URL . 'checkout' . $transactionId . '/end';

        $response = wp_remote_request($api_url, array(
            'method' => 'PUT',
            'headers' => array(
                'x-app-id' => $this->APP_ID,
                'Authorization' => 'Bearer ' . $this->ACCESS_TOKEN,
                'Content-Type' => 'application/json',
                'accept' => 'application/json',
            ),
        ));

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($data['status'] !== 'success') {
            return false;
        }

        return true;
    }



    function decrypt(string $token)
    {
        $input = base64_decode($token);
        $salt = substr($input, 0, 16);
        $nonce = substr($input, 16, 12);
        $ciphertext = substr($input, 28, -16);
        $tag = substr($input, -16);
        $key = hash_pbkdf2("sha256", $this->APP_SECRET, $salt, 40000, 32, true);
        $decryptToken = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, 1, $nonce, $tag);
        $jsonResponse = json_decode($decryptToken, true);
        return $jsonResponse;
    }
}
