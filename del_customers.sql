DELETE um FROM wp_usermeta um INNER JOIN wp_users u ON um.user_id = u.ID WHERE u.user_login != 'admin';
DELETE FROM wp_users WHERE user_login != 'admin';
SELECT COUNT(*) AS usuarios_restantes FROM wp_users;
