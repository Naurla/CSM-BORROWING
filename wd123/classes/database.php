<?php

class Database {
    private $host = "127.0.0.1";
    private $username = "root";
    private $password = "";
    private $dbname = "csm_borrowing";
    
    protected $conn;

    public function connect() {
        $this->conn = new PDO("mysql:host=$this->host;dbname=$this->dbname", $this->username, $this->password);
        return $this->conn;
    }
}
