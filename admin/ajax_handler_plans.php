<?php
require_once "../db_config.php";
session_start();
header('Content-Type: application/json');

// Security check and initial validation
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!isset($_POST['action']) || !isset($_POST['plan_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$plan_id = intval($_POST['plan_id']);
$action = $_POST['action'];
$response = ['success' => false];

mysqli_set_charset($link, "utf8mb4");

if ($action === 'check_dependencies') {
    // Check for any active or future memberships using this plan
    $sql = "SELECT COUNT(id) AS member_count FROM memberships WHERE plan_id = ? AND end_date >= CURDATE()";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "i", $plan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $member_count = $row['member_count'];
    mysqli_stmt_close($stmt);

    if ($member_count > 0) {
        // Dependencies found, fetch other plans for reassignment
        $response['has_dependencies'] = true;
        $response['member_count'] = $member_count;
        $response['message'] = "This plan is assigned to {$member_count} active member(s). You must reassign them before deleting this plan.";

        $other_plans = [];
        $sql_plans = "SELECT id, plan_name, category FROM membership_plans WHERE id != ? ORDER BY category, plan_name";
        $stmt_plans = mysqli_prepare($link, $sql_plans);
        mysqli_stmt_bind_param($stmt_plans, "i", $plan_id);
        mysqli_stmt_execute($stmt_plans);
        $result_plans = mysqli_stmt_get_result($stmt_plans);
        while ($plan_row = mysqli_fetch_assoc($result_plans)) {
            $other_plans[] = $plan_row;
        }
        mysqli_stmt_close($stmt_plans);
        $response['other_plans'] = $other_plans;
    } else {
        // No dependencies
        $response['has_dependencies'] = false;
        $response['message'] = "This plan is not assigned to any active members and can be safely deleted. Are you sure?";
    }
    $response['success'] = true;

} elseif ($action === 'delete_plan') {
    $new_plan_id = isset($_POST['new_plan_id']) ? intval($_POST['new_plan_id']) : 0;

    // Begin transaction
    mysqli_begin_transaction($link);

    try {
        // If reassignment is needed
        if ($new_plan_id > 0) {
            $sql_reassign = "UPDATE memberships SET plan_id = ? WHERE plan_id = ? AND end_date >= CURDATE()";
            $stmt_reassign = mysqli_prepare($link, $sql_reassign);
            mysqli_stmt_bind_param($stmt_reassign, "ii", $new_plan_id, $plan_id);
            mysqli_stmt_execute($stmt_reassign);
            mysqli_stmt_close($stmt_reassign);
        }

        // Delete the plan
        $sql_delete = "DELETE FROM membership_plans WHERE id = ?";
        $stmt_delete = mysqli_prepare($link, $sql_delete);
        mysqli_stmt_bind_param($stmt_delete, "i", $plan_id);
        mysqli_stmt_execute($stmt_delete);
        mysqli_stmt_close($stmt_delete);

        // Commit transaction
        mysqli_commit($link);
        $response['success'] = true;
        $response['message'] = 'Plan successfully deleted.';

    } catch (mysqli_sql_exception $exception) {
        mysqli_rollback($link);
        $response['message'] = 'An error occurred during the delete operation. Transaction rolled back.';
    }
}

mysqli_close($link);
echo json_encode($response);
?>
