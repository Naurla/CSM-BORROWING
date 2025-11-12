<?php
require_once "Database.php";

class Student extends Database {

   // Check if a student with the same student_id already exists (NEW)
    public function isStudentIdExist($student_id) {
        $sql = "SELECT COUNT(*) AS total 
                FROM users 
                WHERE student_id = :student_id";
        $query = $this->connect()->prepare($sql);
        $query->bindParam(":student_id", $student_id);

        if ($query->execute()) {
            $result = $query->fetch();
            return $result["total"] > 0;
        }
        return false;
    }

    // Check if a user with the same email already exists (NEW)
    public function isEmailExist($email) {
        $sql = "SELECT COUNT(*) AS total 
                FROM users 
                WHERE email = :email";
        $query = $this->connect()->prepare($sql);
        $query->bindParam(":email", $email);

        if ($query->execute()) {
            $result = $query->fetch();
            return $result["total"] > 0;
        }
        return false;
    }

    // Register a new student
    public function registerStudent($student_id, $firstname, $lastname, $course, $contact_number, $email, $password) {
        // Hash password for security
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (student_id, firstname, lastname, course, contact_number, email, password, role)
                VALUES (:student_id, :firstname, :lastname, :course, :contact_number, :email, :password, 'student')";

        $query = $this->connect()->prepare($sql);
        $query->bindParam(":student_id", $student_id);
        $query->bindParam(":firstname", $firstname);
        $query->bindParam(":lastname", $lastname);
        $query->bindParam(":course", $course);
        $query->bindParam(":contact_number", $contact_number);
        $query->bindParam(":email", $email);
        $query->bindParam(":password", $hashed_password);

        return $query->execute();
    }

    // Inside classes/Student.php

public function getContactDetails($user_id) {
    $conn = $this->connect();
    $stmt = $conn->prepare("SELECT contact_number FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Inside classes/Student.php

public function updateStudentProfile($user_id, $firstname, $lastname, $course, $contact_number, $email) {
    $conn = $this->connect();
    $sql = "UPDATE users SET 
                firstname = :firstname, 
                lastname = :lastname, 
                course = :course, 
                contact_number = :contact_number, 
                email = :email 
            WHERE id = :id";
            
    $stmt = $conn->prepare($sql);
    
    $stmt->bindParam(":firstname", $firstname);
    $stmt->bindParam(":lastname", $lastname);
    $stmt->bindParam(":course", $course);
    $stmt->bindParam(":contact_number", $contact_number);
    $stmt->bindParam(":email", $email);
    $stmt->bindParam(":id", $user_id);
    
    return $stmt->execute();
}
}
