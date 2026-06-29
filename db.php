<?php
/**
 * Database Connection File
 * 
 * Establishes a connection to the MySQL database for the ERP Portal.
 */

// Establish a connection to the MySQL database (host, user, password, database, port)
$conn=mysqli_connect(
"localhost",
"root",
"",
"erp_portal",3307
);

// Check if the connection was successful, and terminate execution with an error message if it failed
if(!$conn)
{
    die("Connection Failed");
}

?>
