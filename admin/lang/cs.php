<?php

/**
 * Czech admin UI strings.
 * Set  'admin_locale' => 'cs'  in site/config.php to activate.
 */
return [
    'html_lang' => 'cs',

    // Navigace
    // Nastavení
    'settings_title'          => 'Nastavení webu',
    'settings_environment'    => 'Prostředí',
    'settings_php_version'    => 'Verze PHP',
    'settings_cms_version'    => 'Verze Station0',
    'settings_site_name'      => 'Název webu',
    'settings_base_url'       => 'Základní URL',
    'settings_dependencies'   => 'Závislosti',
    'settings_page_templates' => 'Šablony stránek',
    'settings_block_types'    => 'Typy bloků',
    'settings_none'           => 'Nic nenalezeno.',

    'nav_dashboard'     => 'Přehled',
    'nav_structure'     => 'Stránky',
    'nav_streams'       => 'Příspěvky',
    'nav_pages'         => 'Stránky',
    'nav_collections'   => 'Kolekce',
    'collections_title' => 'Kolekce',
    'nav_site_settings' => 'Nastavení webu',
    'nav_users'         => 'Uživatelé',
    'nav_logout'        => 'Odhlásit',

    // Přihlášení
    'login_title'            => 'Přihlášení',
    'login_password_changed' => 'Heslo bylo úspěšně změněno. Přihlaste se.',
    'login_username'         => 'Uživatelské jméno',
    'login_password'         => 'Heslo',
    'login_submit'           => 'Přihlásit se',
    'login_forgot'           => 'Zapomenuté heslo',

    // Nastavení
    'setup_title'    => 'Nastavení',
    'setup_intro'    => 'Vítejte! Vytvořte první správcovský účet.',
    'setup_username' => 'Uživatelské jméno',
    'setup_email'    => 'E-mail',
    'setup_password' => 'Heslo',
    'setup_submit'   => 'Vytvořit účet a přihlásit se',

    // Zapomenuté / obnovení hesla
    'forgot_title'  => 'Zapomenuté heslo',
    'forgot_sent'   => 'Pokud e-mail existuje, brzy obdržíte odkaz pro obnovení hesla.',
    'forgot_email'  => 'E-mail',
    'forgot_submit' => 'Odeslat odkaz',
    'forgot_back'   => 'Zpět na přihlášení',
    'reset_title'        => 'Obnovení hesla',
    'reset_new_link'     => 'Požádat o nový odkaz',
    'reset_new_password' => 'Nové heslo',
    'reset_submit'       => 'Nastavit heslo',

    // Přehled
    'dashboard_title'        => 'Přehled',
    'dashboard_logged_in_as' => 'Přihlášen jako',
    'dashboard_pages'        => 'stránek',
    'dashboard_new_page'     => '+ nová stránka',
    'dashboard_users'        => 'Uživatelé',

    // Seznam stránek
    'pages_title'            => 'Stránky',
    'pages_new'              => '+ Nová',
    'pages_status_draft'     => 'koncept',
    'pages_status_scheduled' => 'plánováno',
    'pages_status_published' => 'publikováno',
    'pages_view'             => 'Zobrazit ↗',
    'pages_add_child'        => '+ Přidat podstránku',
    'pages_edit'             => 'Upravit',
    'pages_delete'           => 'Smazat',
    'pages_empty'            => 'Žádné stránky.',
    'pages_empty_create'     => 'Vytvořte první stránku.',
    'pages_tab_structure'    => '⛶ Webová struktura',
    'pages_tab_streams'      => '≡ Streamy',
    'pages_all_records'      => 'Všech %count% záznamů…',
    'streams_empty'          => 'Žádné stream stránky. Stránka se stane streamem, když deklaruje <code>AllowedChildTemplates</code>.',
    'streams_new_record'     => '+ Nový záznam',

    // Úprava stránky
    'page_field_title'             => 'Titulek',
    'page_field_slug'              => 'Slug',
    'page_field_slug_hint'         => '(volitelné — generováno z názvu)',
    'page_field_parent'            => 'Rodičovská stránka',
    'page_field_template'          => 'Šablona',
    'page_field_metatitle'         => 'Meta titulek',
    'page_field_metatitle_hint'    => '(HTML &lt;title&gt; — pokud prázdné, použije se Titulek)',
    'page_field_published'         => 'Publikováno',
    'page_field_publish_date'      => 'Datum publikování',
    'page_field_publish_date_hint' => '(budoucí datum = plánováno)',
    'page_add_block'               => '+ Přidat blok:',
    'page_cancel'                  => 'Zrušit',
    'page_save'                    => 'Uložit',

    // Editor bloků
    'block_move_up'             => 'Nahoru',
    'block_move_down'           => 'Dolů',
    'block_remove'              => 'Odstranit',
    'block_upload'              => 'Nahrát',
    'block_upload_image_ph'     => "Nahrát\nobrázek",
    'block_file_clear'          => '× Smazat',
    'block_list_item_remove'    => '✕ Odebrat',
    'block_list_item_add'       => '+ Přidat položku',
    'block_gallery_placeholder' => 'Klikněte nebo přetáhněte obrázky',
    'block_gallery_upload'      => '+ Nahrát obrázky',
    'block_files_placeholder'   => 'Klikněte nebo přetáhněte soubory',
    'block_files_upload'        => '+ Nahrát soubory',

    // Editor bloků — JS hlášení
    'js_save_first'     => 'Nejprve uložte stránku — soubory jsou uloženy ve složce stránky.',
    'js_upload_failed'  => 'Nahrání selhalo: ',
    'js_upload_network' => 'Nahrání selhalo: chyba sítě',
    'js_select_images'  => 'Vyberte prosím soubory obrázků.',

    // Uživatelé
    'users_title'         => 'Uživatelé',
    'users_new'           => '+ Nový',
    'users_th_username'   => 'Uživatelské jméno',
    'users_th_email'      => 'E-mail',
    'users_th_role'       => 'Role',
    'users_th_created'    => 'Vytvořeno',
    'users_th_last_login' => 'Poslední přihlášení',
    'users_delete'        => 'smazat',
    'user_new_title'      => 'Nový uživatel',
    'user_field_username' => 'Uživatelské jméno',
    'user_field_email'    => 'E-mail',
    'user_field_password' => 'Heslo',
    'user_field_role'     => 'Role',
    'user_cancel'         => 'Zrušit',
    'user_create'         => 'Vytvořit',

    // Chybové / stavové zprávy (z controllerů)
    'err_invalid_credentials'   => 'Neplatné přihlašovací údaje.',
    'err_too_many_attempts'     => 'Příliš mnoho pokusů. Zkuste to za chvíli.',
    'err_login_failed'          => 'Přihlášení selhalo.',
    'err_email_not_found'       => 'E-mailová adresa nebyla nalezena.',
    'err_reset_disabled'        => 'Reset hesla je pro tento účet zakázán.',
    'err_email_send_failed'     => 'Odeslání e-mailu selhalo. Zkontrolujte nastavení SMTP.',
    'err_reset_link_invalid'    => 'Odkaz pro reset hesla je neplatný nebo vypršel.',
    'err_reset_verify_error'    => 'Nastala chyba při ověřování odkazu.',
    'err_password_min'          => 'Heslo musí mít alespoň 8 znaků.',
    'err_reset_failed'          => 'Reset hesla selhal.',
    'err_fill_all_fields'       => 'Vyplňte všechna pole.',
    'err_invalid_email'         => 'Zadejte platnou e-mailovou adresu.',
    'err_account_create_failed' => 'Vytvoření účtu selhalo. Zkuste to prosím znovu.',
    'err_username_empty'        => 'Uživatelské jméno nesmí být prázdné.',
    'err_delete_self'           => 'Nemůžete smazat vlastní účet.',
    'err_delete_last_admin'     => 'Nelze smazat posledního administrátora.',

    // E-mail pro reset hesla
    'email_reset_subject' => 'Reset hesla – Station0',
    'email_reset_intro'   => 'Pro reset hesla klikněte na odkaz níže. Odkaz je platný 6 hodin.',
    'email_reset_ignore'  => 'Pokud jste reset hesla nepožadovali, tento e-mail ignorujte.',
];
