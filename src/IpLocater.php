<?php

namespace adz\core;



class IpLocater {


    public function __construct() {
      
    }

    public function getCurrent(){
        return self::get(Request::ip()) ;
    }

    Public static function get(string $ip){
        return geoip($ip);
    }
 
    public function routes() {
        require __DIR__ . '/../routes/routes.php';
    }

}
