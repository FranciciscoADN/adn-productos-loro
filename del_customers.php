<?php
require '/var/www/html/wp-load.php';
$users = get_users( array( 'role' => 'customer', 'fields' => 'ID', 'number' => -1 ) );
$count = 0;
foreach ( $users as $id ) {
    wp_delete_user( $id, 1 );
    $count++;
}
echo "Eliminados: $count usuarios\n";
