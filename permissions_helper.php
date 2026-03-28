<?php
/**
 * Permission Helper Functions
 * 
 * Include this file in pages that need permission checking
 * Usage: require_once 'permissions_helper.php';
 */

/**
 * Check if current user has a specific permission
 * 
 * @param PDO $conn Database connection
 * @param string $permission_name Permission name (e.g., 'contracts.create')
 * @return bool True if user has permission, false otherwise
 */
function hasPermission($conn, $permission_name)
{
    // Super admin always has all permissions
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'super_admin') {
        return true;
    }

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.name = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $permission_name]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        // If permissions table doesn't exist or error, deny access
        return false;
    }
}

/**
 * Check if current user has any of the specified permissions
 * 
 * @param PDO $conn Database connection
 * @param array $permission_names Array of permission names
 * @return bool True if user has at least one permission
 */
function hasAnyPermission($conn, $permission_names)
{
    foreach ($permission_names as $perm) {
        if (hasPermission($conn, $perm)) {
            return true;
        }
    }
    return false;
}

/**
 * Require permission - redirect to error page if user doesn't have it
 * 
 * @param PDO $conn Database connection
 * @param string $permission_name Permission name
 * @param string $redirect_url Where to redirect if no permission (default: index.php)
 */
function requirePermission($conn, $permission_name, $redirect_url = 'index.php')
{
    if (!hasPermission($conn, $permission_name)) {
        header("Location: $redirect_url?error=" . urlencode("Bạn không có quyền truy cập chức năng này"));
        exit();
    }
}

/**
 * Get all permissions for current user
 * 
 * @param PDO $conn Database connection
 * @return array Array of permission names
 */
function getUserPermissions($conn)
{
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'super_admin') {
        // Return all permissions for super admin
        $stmt = $conn->query("SELECT name FROM permissions");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!isset($_SESSION['user_id'])) {
        return [];
    }

    try {
        $stmt = $conn->prepare("
            SELECT p.name 
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}
?>