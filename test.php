<?php namespace Innstant\Currency;

use SimpleXMLElement;

class API extends \Innstant\API {

    private $rates;

    private $rate;
    private $fromCurrency;
    private $toCurrency;

    public function __construct($options = array()){

        $this -> rates = array();
        $this -> GetCurrencyRates();

        if (!empty($options)) {
            if ( isset($options['fromCurrency']) ) {
                $this->from( $options['fromCurrency'] );
            }
            if ( isset($options['toCurrency']) ) {
                $this->to( $options['toCurrency'] );
            }
        }
    }
    protected function InitiateSearch(){}
    protected function BuildPollRequest(){}

    /**
     * Function generates package XML request
     *
     * @return string
     */
    protected function BuildSearchRequest(){

        $request = new SimpleXMLElement('<request version="4.0"></request>');
        $this -> AppendNode($request, $this -> AuthenticationNode());
        $request -> addChild('get-currency-rates');

        $request -> asXML($_SERVER['DOCUMENT_ROOT'] . '/logs/innstant/currency_rates_request.xml');
        return $request -> asXML();
    }

   
    /**
     * Function perform request to the API
     *
     * @param array $xmlRequest         Must contain at least on element: data - which contains data to transfer (Can contain additional elements)
     *
     * @return array                    Contain 2 elements: status, data
     */
    protected function PerformRequest($xmlRequest){
        $serverUrl = isset($xmlRequest['server']) ? $xmlRequest['server'] : self::API_URL;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $serverUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $xmlRequest['data']);
        if(isset($xmlRequest['debug']) && $xmlRequest['debug'] == true) {
            curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        }
        $response = curl_exec($curl);

        if(isset($xmlRequest['debug']) && $xmlRequest['debug'] == true) {
            echo 'error: (' . curl_errno($curl) . ') ' . curl_error($curl);
            $debug = curl_getinfo($curl);
            var_dump($debug);
            echo 'post_data: ' . htmlentities($xmlRequest['data']) . "<br>\r\n<br>\r\n<br>\r\n";
            echo 'response: ' . $response;
            exit;
        }

        $httpCode = 0;
        if(curl_errno($curl) == CURLE_OK){
            $debug = curl_getinfo($curl);
            if($debug !== false){
                $httpCode = $debug['http_code'];
            }
        }
        else{
            throw new \RuntimeException('CURL error(): ' . curl_error($curl), curl_errno($curl));
        }

        curl_close($curl);

        return array('status' => $httpCode, 'data' => $response);
    }

    /**
     * @param string $type
     * @param array $request
     *
     * @return SimpleXMLElement
     * @throws \RuntimeException
     */
    protected function ProcessResponse($type, $request){
        $response = $this -> PerformRequest($request);
        $xmlResponse = @simplexml_load_string($response['data'], null, LIBXML_NOCDATA);
        if($xmlResponse === false){
            $time = time();
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/logs/innstant/xml_wrong_response_' . $response['status'] . '_' . $time . '.xml', $xmlResponse);
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/logs/innstant/xml_wrong_request_' . $time . '.xml', $request['data']);
            throw new \RuntimeException('XML response has wrong format! [' . $type . ']');
        }
        else{
            $xmlResponse -> asXML($_SERVER['DOCUMENT_ROOT'] . '/logs/innstant/currency_rates_response.xml');
        }
        return $xmlResponse;
    }

    protected function GetCurrencyRates() {
        $request = array(
            'data' => $this -> BuildSearchRequest()/*,
            'debug' => true*/
        );

        $response   = $this -> ProcessResponse('currency-rate', $request);
        $ratesJSON  = json_encode($response);
        $ratesArray = json_decode($ratesJSON, TRUE);

        foreach ($ratesArray['get-currency-rates']['item'] as $item) {
            $this->rates[$item['source']][$item['target']] = $item['rate'];
        }
    }

    /**
     * @return void
     *
     * @throws OutOfRangeException
     */
    protected function setRate() {
        if (isset($this->rates[$this->fromCurrency])) {
            if (isset($this->rates[$this->fromCurrency][$this->toCurrency])) {
                $this->rate = floatval( $this->rates[$this->fromCurrency][$this->toCurrency] );
            }
            else {
                throw new \OutOfRangeException('<To> Currency not valid or not exists.', 2014);
            }
        }
        else {
            throw new \OutOfRangeException('<From> Currency not valid or not exists.', 2013);
        }
    }


    /***************************
     *  Public API
     ***************************/

    
    /**
     * @param string $currency
     *
     * @return $this (chainable)
     */
    public function from($currency) {
        $this->fromCurrency = $currency;
        return $this;
    }

    /**
     * @param string $currency
     *
     * @return $this (chainable)
     */
    public function to($currency) {
        $this->toCurrency = $currency;
        return $this;
    }

    /**
     * @param float $price, Price $price, Array $price
     *
     * @return float converted price, array of converted prices
     */
    public function convert($price) {
       
        $this->setRate();

        if (is_array($price)) {
            $out = array();
            foreach ($price as $index => $value) {
                $out[$index] = $this->convert($value);
            }
            return $out;
        }

        if ($price instanceof \Innstant\Price) {

            $this->from($price->Currency());
            $price = $price->Amount();

            $this->setRate();

        }

        if (!is_numeric($price)) {
            throw new \InvalidArgumentException('Amount [' . $price . '] is not convertable.', 2015);
        }

        return floatval($price) * $this->rate;
    }

    /**
     *
     * @return float $rate
     */
    public function getRate() {
        $this->setRate();
        return $this->rate;
    }

    /**
     *
     * @return $this (chainable)
     */
    public function shift() {
        
        $from = $this->fromCurrency;
        $to   = $this->toCurrency;

        $this->from($to)->to($from);
        return $this;
    }

} 
