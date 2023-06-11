<?php

namespace adz\core;



class Adz {


    public function __construct() {
      
    }
 
    public function routes() {
        require __DIR__ . '/../routes/routes.php';
    }

}
