<?php

/**
 * English admin UI strings.
 * Set  'admin_locale' => 'en'  in site/config.php (this is the default).
 */
return [
    'html_lang' => 'en',

    // Navigation
    'nav_dashboard'     => 'Dashboard',
    'nav_structure'     => 'Pages',
    'nav_streams'       => 'Streams',
    'nav_pages'         => 'Pages',
    'nav_collections'   => 'Collections',
    'nav_site_settings' => 'Site Settings',
    'nav_users'         => 'Users',
    'nav_logout'        => 'Log out',

    // Login
    'login_title'             => 'Log in',
    'login_password_changed'  => 'Password changed successfully. Please log in.',
    'login_username'          => 'Username',
    'login_password'          => 'Password',
    'login_submit'            => 'Log in',
    'login_forgot'            => 'Forgot password',

    // Setup
    'setup_title'   => 'Setup',
    'setup_intro'   => 'Welcome! Create your first admin account.',
    'setup_username' => 'Username',
    'setup_email'   => 'E-mail',
    'setup_password' => 'Password',
    'setup_submit'  => 'Create account and log in',

    // Forgot / reset password
    'forgot_title'  => 'Forgot password',
    'forgot_sent'   => 'If that e-mail exists you will receive a password reset link shortly.',
    'forgot_email'  => 'E-mail',
    'forgot_submit' => 'Send reset link',
    'forgot_back'   => 'Back to log in',
    'reset_title'         => 'Reset password',
    'reset_new_link'      => 'Request a new link',
    'reset_new_password'  => 'New password',
    'reset_submit'        => 'Set password',

    // Dashboard
    'dashboard_title'        => 'Dashboard',
    'dashboard_logged_in_as' => 'Logged in as',
    'dashboard_pages'        => 'pages',
    'dashboard_new_page'     => '+ new page',
    'dashboard_users'        => 'Users',

    // Pages list
    'pages_title'           => 'Pages',
    'pages_new'             => '+ New',
    'pages_status_draft'    => 'draft',
    'pages_status_scheduled' => 'scheduled',
    'pages_status_published' => 'published',
    'pages_view'            => 'View ↗',
    'pages_add_child'       => '+ Add child page',
    'pages_edit'            => 'Edit',
    'pages_delete'          => 'Delete',
    'pages_empty'           => 'No pages yet.',
    'pages_empty_create'    => 'Create the first one.',
    'pages_tab_structure'   => '⛶ Web structure',
    'pages_tab_streams'     => '≡ Streams',
    'pages_all_records'     => 'All %count% stream records…',
    'streams_empty'         => 'No stream pages yet. A page becomes a stream when it declares <code>AllowedChildTemplates</code>.',
    'streams_new_record'    => '+ New record',

    // Pages edit
    'page_field_title'            => 'Title',
    'page_field_slug'             => 'Slug',
    'page_field_slug_hint'        => '(optional — generated from title)',
    'page_field_parent'           => 'Parent page',
    'page_field_template'         => 'Template',
    'page_field_metatitle'        => 'Meta title',
    'page_field_metatitle_hint'   => '(used for HTML &lt;title&gt; — falls back to Title if empty)',
    'page_field_published'        => 'Published',
    'page_field_publish_date'     => 'Publish date',
    'page_field_publish_date_hint' => '(future date = scheduled)',
    'page_add_block'              => '+ Add block:',
    'page_cancel'                 => 'Cancel',
    'page_save'                   => 'Save',

    // Block editor
    'block_move_up'             => 'Move up',
    'block_move_down'           => 'Move down',
    'block_remove'              => 'Remove',
    'block_upload'              => 'Upload',
    'block_upload_image_ph'     => "Upload\nimage",
    'block_file_clear'          => '× Clear',
    'block_list_item_remove'    => '✕ Remove',
    'block_list_item_add'       => '+ Add item',
    'block_gallery_placeholder' => 'Click or drag images here',
    'block_gallery_upload'      => '+ Upload images',
    'block_files_placeholder'   => 'Click or drag files here',
    'block_files_upload'        => '+ Upload files',

    // Block editor — JS alert strings (serialised as JSON into the page)
    'js_save_first'         => 'Save the page first — files are stored in the page folder.',
    'js_upload_failed'      => 'Upload failed: ',
    'js_upload_network'     => 'Upload failed: network error',
    'js_select_images'      => 'Please select image files.',

    // Collections
    'collections_title'          => 'Collections',
    'collections_empty'          => 'No collections found. Create a <code>_collection.yaml</code> file inside a collection directory to get started.',
    'collections_hint'           => 'Collections are headless content stores — they have no public URL. Use the collection() Twig function to load them in templates.',
    'collections_th_name'        => 'Collection',
    'collections_th_items'       => 'Items',
    'collections_manage'         => 'Manage',
    'collections_new_item'       => '+ New item',
    'collections_items_empty'    => 'No items yet.',
    'collections_th_item_title'  => 'Title',
    'collections_th_status'      => 'Status',
    'collections_th_sort'        => 'Sort',
    'collections_delete_confirm' => 'Delete this item?',
    'collections_field_sort'     => 'Sort',
    'collections_field_body'     => 'Body',
    'collections_field_body_hint' => 'Markdown or YAML block list. Rendered via render_collection_item() in templates.',

    // Settings
    'settings_title'        => 'Site Settings',
    'settings_environment'  => 'Environment',
    'settings_php_version'  => 'PHP version',
    'settings_cms_version'  => 'Station0 version',
    'settings_site_name'    => 'Site name',
    'settings_base_url'     => 'Base URL',
    'settings_dependencies' => 'Dependencies',
    'settings_page_templates' => 'Page templates',
    'settings_block_types'  => 'Block types',
    'settings_none'         => 'None found.',

    // Users
    'users_title'          => 'Users',
    'users_new'            => '+ New',
    'users_th_username'    => 'Username',
    'users_th_email'       => 'E-mail',
    'users_th_role'        => 'Role',
    'users_th_created'     => 'Created',
    'users_th_last_login'  => 'Last login',
    'users_delete'         => 'delete',
    'user_new_title'       => 'New user',
    'user_field_username'  => 'Username',
    'user_field_email'     => 'E-mail',
    'user_field_password'  => 'Password',
    'user_field_role'      => 'Role',
    'user_cancel'          => 'Cancel',
    'user_create'          => 'Create',
];
