<?php

class Database{
    private $host="127.0.0.1";
    private $username="root";
    private $password = "";
    private $dbname = "clearance";

    protected $conn;

    public function connect(){
        try {
            $this->conn = new PDO("mysql:host=$this->host;dbname=$this->dbname", $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->conn;
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public function getAllDepartments() {
        if (!$this->conn) {
            $this->connect();
        }
        $sql = "SELECT department_id, dept_name FROM department";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}