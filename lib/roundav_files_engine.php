<?php

use Sabre\DAV\Client;
use League\Flysystem\Filesystem;
use League\FlySystem\StorageAttributes;
use League\Flysystem\WebDAV\WebDAVAdapter;
class roundav_files_engine
{
    /**
     * @var roundav
     */
    private $plugin;

    private $sort_cols = array('name', 'mtime', 'size');

    private $file_data = [];

    /**
     *
     * @var Filesystem
     */
    protected $filesystem;

    const API_VERSION = 2;


    /**
     * Class constructor
     */
    public function __construct($plugin)
    {
        $this->plugin  = $plugin;

        $settings = array(
            'baseUri' => $plugin->rc->config->get('driver_webdav_url'),
            'userName' => $plugin->rc->config->get('driver_webdav_username') ?? $plugin->rc->user->get_username(),
            'password' => $plugin->rc->config->get('driver_webdav_password') ?? $plugin->rc->get_user_password(),
        );

        $client = new Client($settings);
        $adapter = new WebDAVAdapter($client, $plugin->rc->config->get('driver_webdav_prefix'));
        $this->filesystem = new Filesystem($adapter);
    }

    /**
     * User interface initialization
     */
    public function ui($plugin)
    {
        $plugin->add_texts('localization/');

        // set templates of Files UI and widgets
        if ($plugin->rc->task == 'mail') {
            if ($plugin->rc->action == 'compose') {
                $template = 'compose_plugin';
            }
            else if (in_array($plugin->rc->action, array('show', 'preview', 'get'))) {
                $template = 'message_plugin';

                if ($plugin->rc->action == 'get') {
                    // add "Save as" button into attachment toolbar
                    $plugin->add_button(array(
                        'id'         => 'saveas',
                        'name'       => 'saveas',
                        'type'       => 'link',
                        'onclick'    => 'roundav_directory_selector_dialog()',
                        'class'      => 'button buttonPas saveas',
                        'classact'   => 'button saveas',
                        'label'      => 'roundav.save',
                        'title'      => 'roundav.saveto',
                        ), 'toolbar');
                }
                else {
                    // add "Save as" button into attachment menu
                    $plugin->add_button(array(
                        'id'         => 'attachmenusaveas',
                        'name'       => 'attachmenusaveas',
                        'type'       => 'link',
                        'wrapper'    => 'li',
                        'onclick'    => 'return false',
                        'class'      => 'icon active saveas',
                        'classact'   => 'icon active saveas',
                        'innerclass' => 'icon active saveas',
                        'label'      => 'roundav.saveto',
                        ), 'attachmentmenu');
                }
            }

            $plugin->add_label('save', 'cancel', 'saveto',
                'saveall', 'fromcloud', 'attachsel', 'selectfiles', 'attaching',
                'collection_audio', 'collection_video', 'collection_image', 'collection_document',
                'folderauthtitle', 'authenticating',
                'refreshfolderslist'
            );
        }
        else if ($plugin->rc->task == 'roundav' && $plugin->rc->config->get('show_drive_task', true)) {
            $template = 'files';
        }

        // add taskbar button
        if (empty($_REQUEST['framed']) && $plugin->rc->config->get('show_drive_task', true)) {
            $plugin->add_button(array(
                'command'    => 'roundav',
                'class'      => 'button-files',
                'classsel'   => 'button-files button-selected',
                'innerclass' => 'button-inner',
                'label'      => 'roundav.files',
                ), 'taskbar');
        }

        $plugin->include_stylesheet($plugin->local_skin_path().'/style.css');

        if (!empty($template)) {
            $plugin->include_script('file_api.js');
            $plugin->include_script('roundav.js');

            // register template objects for dialogs (and main interface)
            $plugin->rc->output->add_handlers(array(
                'folder-create-form' => array($this, 'folder_create_form'),
                'folder-edit-form'   => array($this, 'folder_edit_form'),
                'folder-mount-form'  => array($this, 'folder_mount_form'),
                'folder-auth-options'=> array($this, 'folder_auth_options'),
                'file-search-form'   => array($this, 'file_search_form'),
                'file-edit-form'     => array($this, 'file_edit_form'),
                'filelist'           => array($this, 'file_list'),
                'filequotadisplay'   => array($this, 'quota_display'),
            ));

            if ($plugin->rc->task != 'roundav') {
                // add dialog content at the end of page body
                $plugin->rc->output->add_footer(
                    $plugin->rc->output->parse('roundav.' . $template, false, false));
            }
        }
    }

    /**
     * Template object for folder creation form
     */
    public function folder_create_form($attrib)
    {
        $attrib['name'] = 'folder-create-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-create-form';
        }

        $input_name    = new html_inputfield(array('id' => 'folder-name', 'name' => 'name', 'size' => 30));
        $select_parent = new html_select(array('id' => 'folder-parent', 'name' => 'parent'));
        $table         = new html_table(array('cols' => 2, 'class' => 'propform'));

        $table->add('title', html::label('folder-name', rcube::Q($this->plugin->gettext('foldername'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('folder-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->plugin->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('foldercreating', 'foldercreatenotice', 'create', 'foldercreate', 'cancel');
        $this->plugin->rc->output->add_gui_object('folder-create-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder editing form
     */
    public function folder_edit_form($attrib)
    {
        $attrib['name'] = 'folder-edit-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-edit-form';
        }

        $input_name    = new html_inputfield(array('id' => 'folder-edit-name', 'name' => 'name', 'size' => 30));
        $select_parent = new html_select(array('id' => 'folder-edit-parent', 'name' => 'parent'));
        $table         = new html_table(array('cols' => 2, 'class' => 'propform'));

        $table->add('title', html::label('folder-name', rcube::Q($this->plugin->gettext('foldername'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('folder-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->plugin->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('folderupdating', 'folderupdatenotice', 'save', 'folderedit', 'cancel');
        $this->plugin->rc->output->add_gui_object('folder-edit-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder mounting form
     */
    public function folder_mount_form($attrib)
    {
        $sources = $this->plugin->rc->output->get_env('external_sources');

        if (empty($sources) || !is_array($sources)) {
            return '';
        }

        $attrib['name'] = 'folder-mount-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-mount-form';
        }

        // build form content
        $table        = new html_table(array('cols' => 2, 'class' => 'propform'));
        $input_name   = new html_inputfield(array('id' => 'folder-mount-name', 'name' => 'name', 'size' => 30));
        $input_driver = new html_radiobutton(array('name' => 'driver', 'size' => 30));

        $table->add('title', html::label('folder-mount-name', rcube::Q($this->plugin->gettext('name'))));
        $table->add(null, $input_name->show());

        foreach ($sources as $key => $source) {
            $id    = 'source-' . $key;
            $form  = new html_table(array('cols' => 2, 'class' => 'propform driverform'));

            foreach ((array) $source['form'] as $idx => $label) {
                $iid = $id . '-' . $idx;
                $type  = stripos($idx, 'pass') !== false ? 'html_passwordfield' : 'html_inputfield';
                $input = new $type(array('size' => 30));

                $form->add('title', html::label($iid, rcube::Q($label)));
                $form->add(null, $input->show('', array(
                        'id'   => $iid,
                        'name' => $key . '[' . $idx . ']'
                )));
            }

            $row = $input_driver->show(null, array('value' => $key))
                . html::img(array('src' => $source['image'], 'alt' => $key, 'title' => $source['name']))
                . html::div(null, html::span('name', rcube::Q($source['name']))
                    . html::br()
                    . html::span('description', rcube::Q($source['description']))
                    . $form->show()
                );

            $table->add(array('id' => $id, 'colspan' => 2, 'class' => 'source'), $row);
        }

        $out = $table->show() . $this->folder_auth_options(array('suffix' => '-form'));

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->plugin->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('foldermounting', 'foldermountnotice', 'foldermount',
            'save', 'cancel', 'folderauthtitle', 'authenticating'
        );
        $this->plugin->rc->output->add_gui_object('folder-mount-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder authentication options
     */
    public function folder_auth_options($attrib)
    {
        $suffix = $attrib['suffix'] ?? '';
        $checkbox = new html_checkbox(array(
            'name'  => 'store_passwords',
            'value' => '1',
            'id'    => 'auth-pass-checkbox' . $suffix,
        ));

        return html::div('auth-options', $checkbox->show(). '&nbsp;'
            . html::label('auth-pass-checkbox' . $suffix, $this->plugin->gettext('storepasswords'))
            . html::span('description', $this->plugin->gettext('storepasswordsdesc'))
        );
    }

    /**
     * Template object for file_edit form
     */
    public function file_edit_form($attrib)
    {
        $attrib['name'] = 'file-edit-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'file-edit-form';
        }

        $input_name = new html_inputfield(array('id' => 'file-name', 'name' => 'name', 'size' => 30));
        $table      = new html_table(array('cols' => 2, 'class' => 'propform'));

        $table->add('title', html::label('file-name', rcube::Q($this->plugin->gettext('filename'))));
        $table->add(null, $input_name->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->plugin->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('save', 'cancel', 'fileupdating', 'fileedit');
        $this->plugin->rc->output->add_gui_object('file-edit-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for file search form in "From cloud" dialog
     */
    public function file_search_form($attrib)
    {
        $attrib['name'] = '_q';
        $attrib['placeholder'] = 'Search';

        if (empty($attrib['id'])) {
            $attrib['id'] = 'filesearchbox';
        }
        if (isset($attrib['type']) && $attrib['type'] == 'search' && !$this->plugin->rc->output->browser->khtml) {
            unset($attrib['type'], $attrib['results']);
        }

        $input_q = new html_inputfield($attrib);
        $out = $input_q->show();

        // add some labels to client
        $this->plugin->add_label('searching');
        $this->plugin->rc->output->add_gui_object('filesearchbox', $attrib['id']);

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->plugin->rc->output->form_tag(array(
                'action'   => '?_task=files',
                'name'     => "filesearchform",
                'onsubmit' => rcmail_output::JS_OBJECT_NAME . ".command('files-search'); return false",
            ), $out);
        }

        return $out;
    }

    /**
     * Template object for files list
     */
    public function file_list($attrib)
    {
        // define list of cols to be displayed based on parameter or config
        if (empty($attrib['columns'])) {
            $list_cols     = $this->plugin->rc->config->get('roundav_list_cols');
            $dont_override = $this->plugin->rc->config->get('dont_override');
            $a_show_cols = is_array($list_cols) ? $list_cols : array('name');
            $this->plugin->rc->output->set_env('col_movable', !in_array('roundav_list_cols', (array)$dont_override));
        }
        else {
            $a_show_cols = preg_split('/[\s,;]+/', strip_quotes($attrib['columns']));
        }

        // make sure 'name' and 'options' column is present
        if (!in_array('name', $a_show_cols)) {
            array_unshift($a_show_cols, 'name');
        }
        if (!in_array('options', $a_show_cols)) {
            array_unshift($a_show_cols, 'options');
        }

        $attrib['columns'] = $a_show_cols;

        // save some variables for use in ajax list
        $_SESSION['roundav_list_attrib'] = $attrib;

        // For list in dialog(s) remove all option-like columns
        if ($this->plugin->rc->task != 'roundav') {
            $a_show_cols = array_intersect($a_show_cols, $this->sort_cols);
        }

        // set default sort col/order to session
        if (!isset($_SESSION['roundav_sort_col']))
            $_SESSION['roundav_sort_col'] = $this->plugin->rc->config->get('roundav_sort_col') ?: 'name';
        if (!isset($_SESSION['roundav_sort_order']))
            $_SESSION['roundav_sort_order'] = strtoupper($this->plugin->rc->config->get('roundav_sort_order') ?: 'asc');

        // set client env
        $this->plugin->rc->output->add_gui_object('filelist', $attrib['id']);
        $this->plugin->rc->output->set_env('sort_col', $_SESSION['roundav_sort_col']);
        $this->plugin->rc->output->set_env('sort_order', $_SESSION['roundav_sort_order']);
        $this->plugin->rc->output->set_env('coltypes', $a_show_cols);
        $this->plugin->rc->output->set_env('search_threads', $this->plugin->rc->config->get('roundav_search_threads'));

        $this->plugin->rc->output->include_script('list.js');

        // attach css rules for mimetype icons
        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/mimetypes/style.css');

        $thead = '';
        foreach ($this->file_list_head($attrib, $a_show_cols) as $cell) {
            $thead .= html::tag('th', array('class' => $cell['className'], 'id' => $cell['id']), $cell['html']);
        }

        return html::tag('table', $attrib,
            html::tag('thead', null, html::tag('tr', null, $thead)) . html::tag('tbody', null, ''),
            array('style', 'class', 'id', 'cellpadding', 'cellspacing', 'border', 'summary'));
    }

    /**
     * Creates <THEAD> for message list table
     */
    protected function file_list_head($attrib, $a_show_cols)
    {
        $skin_path = $this->plugin->local_skin_path();

        // check to see if we have some settings for sorting
        $sort_col   = $_SESSION['roundav_sort_col'];
        $sort_order = $_SESSION['roundav_sort_order'];

        $dont_override  = (array)$this->plugin->rc->config->get('dont_override');
        $disabled_sort  = in_array('message_sort_col', $dont_override);
        $disabled_order = in_array('message_sort_order', $dont_override);

        $this->plugin->rc->output->set_env('disabled_sort_col', $disabled_sort);
        $this->plugin->rc->output->set_env('disabled_sort_order', $disabled_order);

        // define sortable columns
        if ($disabled_sort)
            $a_sort_cols = $sort_col && !$disabled_order ? array($sort_col) : array();
        else
            $a_sort_cols = $this->sort_cols;

        if (!empty($attrib['optionsmenuicon'])) {
            $onclick = 'return ' . rcmail_output::JS_OBJECT_NAME . ".command('menu-open', 'filelistmenu', this, event)";
            $inner   = $this->plugin->rc->gettext('listoptions');

            if (is_string($attrib['optionsmenuicon']) && $attrib['optionsmenuicon'] != 'true') {
                $inner = html::img(array('src' => $skin_path . $attrib['optionsmenuicon'], 'alt' => $this->plugin->rc->gettext('listoptions')));
            }

            $list_menu = html::a(array(
                'href'     => '#list-options',
                'onclick'  => $onclick,
                'class'    => 'listmenu',
                'id'       => 'listmenulink',
                'title'    => $this->plugin->rc->gettext('listoptions'),
                'tabindex' => '0',
            ), $inner);
        }
        else {
            $list_menu = '';
        }

        $cells = array();

        foreach ($a_show_cols as $col) {
            // get column name
            switch ($col) {
                case 'options':
                    $col_name = $list_menu;
                    break;
                default:
                    $col_name = rcube::Q($this->plugin->gettext($col));
            }

            // make sort links
            if (in_array($col, $a_sort_cols)) {
                $col_name = html::a(array(
                        'href'    => "#sort",
                        'onclick' => 'return ' . rcmail_output::JS_OBJECT_NAME . ".command('files-sort','$col',this)",
                        'title'   => $this->plugin->gettext('sortby')
                    ), $col_name);
            }
            else if ($col_name[0] != '<')
                $col_name = '<span class="' . $col .'">' . $col_name . '</span>';

            $sort_class = $col == $sort_col && !$disabled_order ? " sorted$sort_order" : '';
            $class_name = $col.$sort_class;

            // put it all together
            $cells[] = array('className' => $class_name, 'id' => "rcm$col", 'html' => $col_name);
        }

        return $cells;
    }

    /**
     * Update files list object
     */
    protected function file_list_update($prefs)
    {
        $attrib = $_SESSION['roundav_list_attrib'];

        if (!empty($prefs['roundav_list_cols'])) {
            $attrib['columns'] = $prefs['roundav_list_cols'];
            $_SESSION['roundav_list_attrib'] = $attrib;
        }

        $a_show_cols = $attrib['columns'];
        $head        = '';

        foreach ($this->file_list_head($attrib, $a_show_cols) as $cell) {
            $head .= html::tag('td', array('class' => $cell['className'], 'id' => $cell['id']), $cell['html']);
        }

        $head = html::tag('tr', null, $head);

        $this->plugin->rc->output->set_env('coltypes', $a_show_cols);
        $this->plugin->rc->output->command('files_list_update', $head);
    }

    /**
     * Template object for file info box
     */
    public function file_info_box($attrib)
    {
        $table = new html_table(array('cols' => 2, 'class' => $attrib['class']));

        // file name
        $table->add('label', $this->plugin->gettext('name').':');
        $table->add('data filename', $this->file_data['name']);

        // file type
        // @TODO: human-readable type name
        $table->add('label', $this->plugin->gettext('type').':');
        $table->add('data filetype', $this->file_data['type']);

        // file size
        $table->add('label', $this->plugin->gettext('size').':');
        $table->add('data filesize', $this->plugin->rc->show_bytes($this->file_data['size']));

        // file modification time
        $table->add('label', $this->plugin->gettext('mtime').':');
        $table->add('data filemtime', $this->file_data['mtime']);

        // @TODO: for images: width, height, color depth, etc.
        // @TODO: for text files: count of characters, lines, words

        return $table->show();
    }

    /**
     * Template object for file preview frame
     */
    public function file_preview_frame($attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'filepreviewframe';
        }

        if ($frame = $this->file_data['viewer']['frame']) {
            return $frame;
        }

        $href = $this->plugin->rc->url(array('task' => 'roundav', 'action' => 'file_api')) . '&method=file_get&file='. urlencode($this->file_data['filename']);

        $this->plugin->rc->output->add_gui_object('preview_frame', $attrib['id']);

        $attrib['allowfullscreen'] = true;
        $attrib['src']             = $href;
        $attrib['onload']          = 'roundav_frame_load(this)';

        return html::iframe($attrib);
    }

    /**
     * Template object for quota display
     */
    public function quota_display($attrib)
    {
        if (!$attrib['id']) {
            $attrib['id'] = 'rcmquotadisplay';
        }

        $quota_type = !empty($attrib['display']) ? $attrib['display'] : 'text';

        $this->plugin->rc->output->add_gui_object('quotadisplay', $attrib['id']);
        $this->plugin->rc->output->set_env('quota_type', $quota_type);

        // get quota
        $quota = array("used" => 0, "total" => 1024);

        $quota = rcube_output::json_serialize($quota);

        $this->plugin->rc->output->add_script(rcmail_output::JS_OBJECT_NAME . ".files_set_quota($quota);", 'docready');

        return html::span($attrib, '');
    }

    /**
     * Handler for main files interface (Files task)
     */
    public function action_index($plugin)
    {
        $plugin->add_texts('localization/');

        $plugin->add_label(
            'folderdeleting', 'folderdeleteconfirm', 'folderdeletenotice',
            'uploading', 'attaching', 'uploadsizeerror',
            'filedeleting', 'filedeletenotice', 'filedeleteconfirm',
            'filemoving', 'filemovenotice', 'filemoveconfirm', 'filecopying', 'filecopynotice',
            'collection_audio', 'collection_video', 'collection_image', 'collection_document',
            'fileskip', 'fileskipall', 'fileoverwrite', 'fileoverwriteall',
            'refreshfolderslist'
        );

        $plugin->add_label('uploadprogress', 'GB', 'MB', 'KB', 'B');
        $plugin->rc->output->set_pagetitle($plugin->gettext('files'));
        $plugin->rc->output->set_env('file_mimetypes', $this->get_mimetypes());
        $plugin->rc->output->set_env('files_quota', $_SESSION['roundav_caps']['QUOTA']);
        $plugin->rc->output->set_env('files_max_upload', $_SESSION['roundav_caps']['MAX_UPLOAD']);
        $plugin->rc->output->set_env('files_progress_name', $_SESSION['roundav_caps']['PROGRESS_NAME']);
        $plugin->rc->output->set_env('files_progress_time', $_SESSION['roundav_caps']['PROGRESS_TIME']);
        $plugin->rc->output->send('roundav.files');
    }

    /**
     * Handler for preferences save action
     */
    public function action_prefs($plugin)
    {
        $plugin->add_texts('localization/');

        $dont_override = (array) $plugin->rc->config->get('dont_override');
        $prefs = array();
        $opts  = array(
            'roundav_sort_col' => true,
            'roundav_sort_order' => true,
            'roundav_list_cols' => false,
        );

        foreach ($opts as $o => $sess) {
            if (isset($_POST[$o]) && !in_array($o, $dont_override)) {
                $prefs[$o] = rcube_utils::get_input_value($o, rcube_utils::INPUT_POST);
                if ($sess) {
                    $_SESSION[$o] = $prefs[$o];
                }

                if ($o == 'roundav_list_cols') {
                    $update_list = true;
                }
            }
        }

        // save preference values
        if (!empty($prefs)) {
            $plugin->rc->user->save_prefs($prefs);
        }

        if (!empty($update_list)) {
            $this->file_list_update($prefs);
        }

        $plugin->rc->output->send();
    }

    /**
     * Handler for file open action
     */
    public function action_open($plugin)
    {
        $plugin->add_texts('localization/');

        $file = urldecode(rcube_utils::get_input_value('file', rcube_utils::INPUT_GET));
        $file = str_replace($plugin->gettext('files'), '/', $file);

        try {
          $this->file_data['type'] = $this->filesystem->mimeType($file);
          $this->file_data['size'] = $this->filesystem->fileSize($file);
          $this->file_data['mtime'] = $this->filesystem->lastModified($file);
        }
        catch (Exception $e) {
          rcube::raise_error(array(
                  'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                  'message' => $e->getMessage()),
              true, true);
        }


        $this->file_data['filename'] = urldecode(rcube_utils::get_input_value('file', rcube_utils::INPUT_GET));

        $plugin->add_label('filedeleteconfirm', 'filedeleting', 'filedeletenotice');

        // register template objects for dialogs (and main interface)
        $plugin->rc->output->add_handlers(array(
            'fileinfobox'      => array($this, 'file_info_box'),
            'filepreviewframe' => array($this, 'file_preview_frame'),
        ));

        // this one is for styling purpose
        $plugin->rc->output->set_env('extwin', true);
        $plugin->rc->output->set_env('file', $file);
        $plugin->rc->output->set_env('file_data', $this->file_data);
        $plugin->rc->output->set_pagetitle(rcube::Q($file));
        $plugin->rc->output->send('roundav.filepreview');
    }

    /**
     * Handler for "save all attachments into cloud" action
     */
    public function action_save_file($plugin)
    {
        $plugin->add_texts('localization/');

        $uid    = rcube_utils::get_input_value('uid', rcube_utils::INPUT_POST);
        $dest   = rcube_utils::get_input_value('dest', rcube_utils::INPUT_POST);
        $id     = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $name   = rcube_utils::get_input_value('name', rcube_utils::INPUT_POST);

        $temp_dir = unslashify($plugin->rc->config->get('temp_dir'));
        $message  = new rcube_message($uid);
        $files    = array();
        $errors   = array();
        $attachments = array();

        foreach ($message->attachments as $attach_prop) {
            if (empty($id) || $id == $attach_prop->mime_id) {
                $filename = !is_null($name) && strlen($name) ? $name : $plugin->get_attachment_name($attach_prop, true);
                $attachments[$filename] = $attach_prop;
            }
        }

        // @TODO: handle error
        // @TODO: implement file upload using file URI instead of body upload

        foreach ($attachments as $attach_name => $attach_prop) {
            $path = tempnam($temp_dir, 'rcmAttmnt');

            // save attachment to file
            if ($fp = fopen($path, 'w+')) {
                $message->get_part_body($attach_prop->mime_id, false, 0, $fp);
            }
            else {
                $errors[] = true;
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => "Unable to save attachment into file $path"),
                    true, false);
                continue;
            }

            fclose($fp);

            // send request to the API
            try {
                if (!is_null($dest)) {
                    $dest = str_replace($plugin->gettext('files'), '/', $dest);
                }

                $this->filesystem->write($dest .  '/' . $attach_name, file_get_contents($path));
                $files[] = $attach_name;
            }
            catch (Exception $e) {
                unlink($path);
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            // clean up
            unlink($path);
        }

        if ($count = count($files)) {
            $msg = $plugin->gettext(array('name' => 'saveallnotice', 'vars' => array('n' => $count)));
            $plugin->rc->output->show_message($msg, 'confirmation');
        }
        if ($count = count($errors)) {
            $msg = $plugin->gettext(array('name' => 'saveallerror', 'vars' => array('n' => $count)));
            $plugin->rc->output->show_message($msg, 'error');
        }

        // @TODO: update quota indicator, make this optional in case files aren't stored in IMAP

        $plugin->rc->output->send();
    }

    /**
     * Handler for "add attachments from the cloud" action
     */
    public function action_attach_file($plugin)
    {
        $plugin->add_texts('localization/');

        $files      = rcube_utils::get_input_value('files', rcube_utils::INPUT_POST);
        $uploadid   = rcube_utils::get_input_value('uploadid', rcube_utils::INPUT_POST);
        $COMPOSE_ID = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $COMPOSE    = null;
        $errors     = array();

        if ($COMPOSE_ID && $_SESSION['compose_data_'.$COMPOSE_ID]) {
            $COMPOSE =& $_SESSION['compose_data_'.$COMPOSE_ID];
        }

        if (!$COMPOSE) {
            die("Invalid session var!");
        }

        // attachment upload action
        if (!isset($COMPOSE['attachments']) || !is_array($COMPOSE['attachments'])) {
            $COMPOSE['attachments'] = array();
        }

        // clear all stored output properties (like scripts and env vars)
        $plugin->rc->output->reset();

        $temp_dir = unslashify($plugin->rc->config->get('temp_dir'));

        // download files from the API and attach them
        foreach ($files as $file) {
            // decode filename
            $file = urldecode($file);
            $file = str_replace($plugin->gettext('files'), '/', $file);

            // set location of downloaded file
            $path = tempnam($temp_dir, 'rcmAttmnt');

            try {
                // save attachment to file
                if ($fp = fopen($path, 'w+')) {
                    fwrite($fp, $this->filesystem->read($file));
                }
                else {
                    $errors[] = "Can't open temporary file";
                    rcube::raise_error([
                            'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                            'message' => "Can't open temporary file"
                        ],
                        true,
                        false
                    );
                    continue;
                }
                fclose($fp);
            }
            catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error([
                        'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                        'message' => $e->getMessage()
                    ],
                    true,
                    false
                );
                continue;
            }

            $attachment = array(
                'path' => $path,
                'size' => $this->filesystem->fileSize($file),
                'name' => $this->get_filename_from_path(urldecode($file)),
                'mimetype' => $this->filesystem->mimeType($file),
                'group' => $COMPOSE_ID,
            );

            $attachment = $plugin->rc->plugins->exec_hook('attachment_save', $attachment);

            if ($attachment['status'] && !$attachment['abort']) {
                $id = $attachment['id'];

                // store new attachment in session
                unset($attachment['data'], $attachment['status'], $attachment['abort']);
                $COMPOSE['attachments'][$id] = $attachment;

                if ((isset($COMPOSE['deleteicon']) && $icon = $COMPOSE['deleteicon']) && is_file($icon)) {
                    $button = html::img(array(
                        'src' => $icon,
                        'alt' => $plugin->rc->gettext('delete')
                    ));
                }
                else {
                    $button = rcube::Q($plugin->rc->gettext('delete'));
                }

                $content = html::a(array(
                    'href' => "#delete",
                    'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this)", rcmail_output::JS_OBJECT_NAME, $id),
                    'title' => $plugin->rc->gettext('delete'),
                    'class' => 'delete',
                ), $button);

                $content .= rcube::Q($attachment['name']);

                $plugin->rc->output->command('add2attachment_list', "rcmfile$id", array(
                    'html'      => $content,
                    'name'      => $attachment['name'],
                    'mimetype'  => $attachment['mimetype'],
                    'classname' => rcube_utils::file2class($attachment['mimetype'], $attachment['name']),
                    'complete'  => true), $uploadid);
            }
            else if ($attachment['error']) {
                $errors[] = $attachment['error'];
            }
            else {
                $errors[] = $plugin->gettext('attacherror');
            }
        }

        if (!empty($errors)) {
            $plugin->rc->output->command('display_message', $plugin->gettext('attacherror'), 'error');
            $plugin->rc->output->command('remove_from_attachment_list', $uploadid);
        }

        // send html page with JS calls as response
        $plugin->rc->output->command('auto_save_start', false);
        $plugin->rc->output->send();
    }

    /**
     * Handler for "folders list" function
     */
    public function action_folder_list($plugin)
    {
        $plugin->add_texts('localization/');

        $result = array(
            'status' => 'OK',
            'result' => array(),
            'req_id' => rcube_utils::get_input_value('req_id', rcube_utils::INPUT_GET),
        );

        $folders = null;
        $force_refresh = rcube_utils::get_input_value('force_refresh', rcube_utils::INPUT_GET) === "true";

        if (isset($_SESSION[$plugin::SESSION_FOLDERS_LIST_ID])) {
            if ($force_refresh) {
                unset($_SESSION[$plugin::SESSION_FOLDERS_LIST_ID]);
            } else {
                $folders = $_SESSION[$plugin::SESSION_FOLDERS_LIST_ID];
            }
        }

        try {
            if (is_null($folders)) {
                $filesPrefix = $plugin->gettext('files');
                $folders = $this->filesystem->listContents('/', true)
                    ->filter(fn (StorageAttributes $attributes) => $attributes->isDir())
                    ->map(fn (StorageAttributes $attributes) => $filesPrefix .'/'.urldecode($attributes->path()))
                    ->toArray();

                array_unshift($folders, $filesPrefix);

                $_SESSION[$plugin::SESSION_FOLDERS_LIST_ID] = $folders;
            }

            $result['result'] = $folders;
        }
        catch (Exception $e) {
            $result['status'] = 'NOK';
            $result['reason'] = "Can't list folders: “" . $e->getMessage() . "”";
        }
        echo json_encode($result);
        exit();
    }

    /**
     * Handler for "file list" function
     */
    public function action_file_list($plugin)
    {
        $plugin->add_texts('localization/');

        $result = array(
            'status' => 'OK',
            'result' => array(),
            'req_id' => rcube_utils::get_input_value('req_id', rcube_utils::INPUT_GET),
        );

        $searchKeyword = '';
        $searchType = '';

        $search = rcube_utils::get_input_value('search', rcube_utils::INPUT_GET);
        if (!empty($search)) {
            if (is_array($search)) {
                $searchKeyword = strtolower($search['name'] ?? '');
                $searchType = strtolower($search['class'] ?? '');
            } else {
                $searchKeyword = strtolower($search);
            }
        }

        try {
            $filesPrefix = $plugin->gettext('files');
            $folder = str_replace($filesPrefix, '/', rcube_utils::get_input_value('folder', rcube_utils::INPUT_GET));
            $files = [];

            $fsFiles = $this->filesystem->listContents($folder, false)
                ->filter(fn (StorageAttributes $attributes) => $attributes->isFile());

            if (!empty($searchKeyword) || !empty($searchType)) {
                $fsFiles = $fsFiles->filter(function (StorageAttributes $attributes) use ($searchKeyword, $searchType) {
                    if (!$attributes->isFile()) {
                        return false;
                    }

                    if (!empty($searchKeyword)) {
                        $name = strtolower(urldecode(basename($attributes->path())));

                        if (strpos($name, $searchKeyword) === false)
                        {
                            return false;
                        }
                    }

                    if (!empty($searchType)) {
                        $fileType = explode('/', $attributes['mimeType'] ?? '')[0];
                        if ($searchType !== $fileType)
                        {
                            return false;
                        }
                    }

                    return true;
                });
            }

            foreach ($fsFiles as $fsfile) {
                $key = urlencode($filesPrefix. '/'. urldecode($fsfile->path()));
                $files[$key] = [
                    'name' => urldecode(basename($fsfile->path())),
                    'type' => $fsfile['mimeType'],
                    'size' => $fsfile['fileSize'],
                    'mtime' => $fsfile['lastModified'],
                ];
            }

            $result['result'] = $files;
        }
        catch (Exception $e) {
            $result['status'] = 'NOK';
            $result['reason'] = "Can't list files: “" . $e->getMessage() . "”";
        }
        echo json_encode($result);
        exit();
    }

    /**
     * Handler for "folder create" function
     */
    public function action_folder_create($plugin)
    {
        $plugin->add_texts('localization/');

        $result = array(
            'status' => 'OK',
            'req_id' => rcube_utils::get_input_value('req_id', rcube_utils::INPUT_GET),
        );
        try {
            $folder = urldecode(rcube_utils::get_input_value('folder', rcube_utils::INPUT_POST));

            // See https://github.com/thephpleague/flysystem/issues/1689
            $this->filesystem->createDirectory(
                str_replace($plugin->gettext('files'), '', $folder)
            );

            if (isset($_SESSION[$plugin::SESSION_FOLDERS_LIST_ID])) {
                $_SESSION[$plugin::SESSION_FOLDERS_LIST_ID][] = $folder;
                sort($_SESSION[$plugin::SESSION_FOLDERS_LIST_ID]);
            }
        }
        catch (Exception $e) {
            $result['status'] = 'NOK';
            $result['reason'] = "Can't create folder: “" . $e->getMessage() . "”";
        }
        echo json_encode($result);
        exit();
    }

    /**
     * Handler for "file get" function
     */
    public function action_file_get($plugin)
    {
        try {
            $file = str_replace($plugin->gettext('files'), '/', rcube_utils::get_input_value('file', rcube_utils::INPUT_GET));

            header('Content-Type: ' . $this->filesystem->mimeType($file));
            header('Content-disposition: attachment; filename=' . $this->get_filename_from_path(urldecode($file)));
            header('Content-Length: ' . $this->filesystem->fileSize($file));
            echo $this->filesystem->read($file);
        }
        catch (Exception $e) {}

        exit();
    }

    /**
     * Returns mimetypes supported by File API viewers
     */
    protected function get_mimetypes()
    {
        return array();
    }

    /**
     * Convertit le chemin en nom de fichier
     * @param string $path
     * @return string
     */
    protected function get_filename_from_path($path)
    {
        $filename = $path;
        $tmp = explode('/', $path);
        if (is_array($tmp) && count($tmp) > 0) {
            $filename = end($tmp);
        }
        return $filename;
    }
}
