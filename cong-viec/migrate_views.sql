-- =====================================================
-- VIEWS: Map TC tables → CV table names
-- Cho phép code CV cũ hoạt động với DB TC
-- Chạy SAU migrate_cv.sql
-- =====================================================

-- VIEW: customers (CV code đọc từ đây)
-- Map loans + TC customers → giả lập bảng customers CV
CREATE OR REPLACE VIEW cv_customers_view AS
SELECT 
    l.id,
    c.name,
    c.phone,
    c.identity_card as cccd,
    c.address,
    c.gender,
    c.date_of_birth,
    l.cv_room_id as room_id,
    l.cv_assigned_to as assigned_to,
    l.cv_status as status,
    l.cv_due_date as due_date,
    l.cv_transfer_date as transfer_date,
    l.cv_notes as notes,
    l.cv_pinned_note as pinned_note,
    l.cv_description as description,
    l.cv_planned_next_room_id as planned_next_room_id,
    l.cv_drive_folder_id as drive_folder_id,
    l.store_id,
    l.loan_code,
    l.amount as loan_amount,
    l.interest_rate,
    l.start_date as loan_start_date,
    l.status as loan_status,
    c.id as tc_customer_id,
    l.id as loan_id,
    '' as hktt,
    '' as facebook_link,
    l.store_id as company_tag_id,
    (SELECT s.name FROM stores s WHERE s.id = l.store_id) as company_tag,
    '' as workplace,
    '' as relatives_info,
    '' as tc_info,
    l.created_at
FROM loans l
LEFT JOIN customers c ON l.customer_id = c.id
WHERE l.status != 'closed';
