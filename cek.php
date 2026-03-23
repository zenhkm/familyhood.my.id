<?php
echo "<pre>";
// Cek versi OS
if (file_exists('/etc/os-release')) {
    echo file_get_contents('/etc/os-release');
} elseif (file_exists('/etc/redhat-release')) {
    echo file_get_contents('/etc/redhat-release');
} else {
    echo php_uname();
}
echo "</pre>";
?>