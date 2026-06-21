<?php
// PHP Weathermap datasource plugin for LibreNMS port status.
//
// TARGET examples:
//   TARGET librenmsportstatus:128117
//   TARGET librenmsportstatus:itipmst1:Ethernet1/5
//   TARGET librenmsportstatus:{node:this:parent_device}:{node:this:ifname}
//   TARGET librenmsportstatus:itspmst1202:Ethernet1/43,Ethernet1/43.1,Ethernet1/43.2
//
// Returns numeric status to Weathermap:
//   0 = unknown/no DB match        -> grey
//   1 = ifOperStatus up            -> green
//   2 = ifAdminStatus down/shutdown-> amber
//   3 = admin up but oper down     -> red
//   4 = oper/admin up with recent errors -> orange
//
// Comma-separated ifName values are supported. For multiple interfaces the
// datasource returns the worst status and exposes graph URL hints:
//   librenms_graph_url_1, librenms_graph_url_2, ... librenms_graph_url_10
// IMPORTANT: Weathermap splits OVERLIBGRAPH URLs while reading config, before
// token expansion. So use fixed multi graph templates, for example:
//   OVERLIBGRAPH {node:this:librenms_graph_url_1} {node:this:librenms_graph_url_2}
// Do not use one token containing a space-separated list for multiple graphs.
//
// This version auto-reads LibreNMS existing DB settings from /opt/librenms/.env
// by finding the LibreNMS root from the Weathermap plugin path/config.inc.php.
// No API, no per-render config rewrite, no separate DB config file required.

class WeatherMapDataSource_librenmsportstatus extends WeatherMapDataSource
{
    var $db = NULL;
    var $db_driver = '';
    var $db_cfg = NULL;
    var $status_cache = array();
    var $ports_columns = NULL;
    var $warning_columns = NULL;

    function Init(&$map)
    {
        $has_pdo_mysql = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers());
        $has_mysqli = class_exists('mysqli');

        if (!$has_pdo_mysql && !$has_mysqli) {
            wm_warn("LibreNMSPortStatus: neither pdo_mysql nor mysqli is available in this PHP runtime. Install/enable php-mysqlnd or php-mysql. [WMLNMSPORT01]\n");
            return FALSE;
        }

        if ($has_pdo_mysql) {
            $this->db_driver = 'pdo';
            wm_debug("LibreNMSPortStatus: using PDO mysql driver.\n");
        } else {
            $this->db_driver = 'mysqli';
            wm_debug("LibreNMSPortStatus: using mysqli driver.\n");
        }

        return TRUE;
    }

    function Recognise($targetstring)
    {
        if (preg_match('/^librenmsportstatus:(\d+)$/', $targetstring)) {
            return TRUE;
        }

        if (preg_match('/^librenmsportstatus:([^:]+):(.+)$/', $targetstring)) {
            return TRUE;
        }

        return FALSE;
    }

    function _first_non_empty($values, $default)
    {
        foreach ($values as $value) {
            if ($value !== FALSE && $value !== NULL && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    function _strip_quotes($value)
    {
        $value = trim((string)$value);
        $len = strlen($value);

        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    function _remove_inline_comment($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $value;
        }

        // Keep quoted strings untouched.
        $first = $value[0];
        if ($first === '"' || $first === "'") {
            return $value;
        }

        // Laravel-style .env values rarely use inline comments, but support them.
        $pos = strpos($value, ' #');
        if ($pos !== FALSE) {
            return trim(substr($value, 0, $pos));
        }

        return $value;
    }

    function _merge_non_empty($base, $overlay)
    {
        foreach ($overlay as $key => $value) {
            if ($value !== NULL && $value !== '') {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    function _default_config()
    {
        return array(
            'host'     => $this->_first_non_empty(array(getenv('LIBRENMS_DB_HOST'), getenv('DB_HOST')), ''),
            'port'     => $this->_first_non_empty(array(getenv('LIBRENMS_DB_PORT'), getenv('DB_PORT')), ''),
            'database' => $this->_first_non_empty(array(getenv('LIBRENMS_DB_DATABASE'), getenv('LIBRENMS_DB_NAME'), getenv('DB_DATABASE')), ''),
            'username' => $this->_first_non_empty(array(getenv('LIBRENMS_DB_USERNAME'), getenv('LIBRENMS_DB_USER'), getenv('DB_USERNAME')), ''),
            'password' => $this->_first_non_empty(array(getenv('LIBRENMS_DB_PASSWORD'), getenv('LIBRENMS_DB_PASS'), getenv('DB_PASSWORD')), ''),
            'socket'   => $this->_first_non_empty(array(getenv('LIBRENMS_DB_SOCKET'), getenv('DB_SOCKET')), ''),
        );
    }

    function _parse_env_file($path)
    {
        $cfg = array();

        if (!is_readable($path)) {
            return $cfg;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === FALSE) {
            return $cfg;
        }

        $map = array(
            'DB_HOST'     => 'host',
            'DB_PORT'     => 'port',
            'DB_DATABASE' => 'database',
            'DB_USERNAME' => 'username',
            'DB_PASSWORD' => 'password',
            'DB_SOCKET'   => 'socket',
        );

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === FALSE) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = $this->_strip_quotes($this->_remove_inline_comment($value));

            if (isset($map[$key])) {
                $cfg[$map[$key]] = $value;
            }
        }

        return $cfg;
    }

    function _parse_legacy_config_php($path)
    {
        $cfg = array();

        if (!is_readable($path)) {
            return $cfg;
        }

        $text = file_get_contents($path);
        if ($text === FALSE) {
            return $cfg;
        }

        $map = array(
            'db_host'   => 'host',
            'db_port'   => 'port',
            'db_name'   => 'database',
            'db_user'   => 'username',
            'db_pass'   => 'password',
            'db_socket' => 'socket',
        );

        foreach ($map as $old => $new) {
            $pattern = '/\$config\s*\[\s*[\'\"]' . preg_quote($old, '/') . '[\'\"]\s*\]\s*=\s*(?:[\'\"]([^\'\"]*)[\'\"]|([^;\s]+))\s*;/';
            if (preg_match($pattern, $text, $matches)) {
                if (isset($matches[1]) && $matches[1] !== '') {
                    $cfg[$new] = $matches[1];
                } elseif (isset($matches[2]) && $matches[2] !== '') {
                    $cfg[$new] = trim($matches[2]);
                }
            }
        }

        return $cfg;
    }

    function _get_weathermap_root()
    {
        // datasource file path: Weathermap/lib/datasources/WeatherMapDataSource_*.php
        $root = realpath(dirname(__FILE__) . '/../..');
        if ($root !== FALSE) {
            return $root;
        }
        return '';
    }

    function _get_librenms_base_from_weathermap_config()
    {
        $wm_root = $this->_get_weathermap_root();
        if ($wm_root === '') {
            return '';
        }

        $config_inc = $wm_root . '/config.inc.php';
        if (!is_readable($config_inc)) {
            return '';
        }

        $old_cwd = getcwd();
        $librenms_base = '';

        @chdir($wm_root);
        @include $config_inc;
        if ($old_cwd !== FALSE) {
            @chdir($old_cwd);
        }

        if (isset($librenms_base) && $librenms_base !== '') {
            $real = realpath($librenms_base);
            if ($real !== FALSE) {
                return $real;
            }
            return $librenms_base;
        }

        return '';
    }

    function _candidate_roots()
    {
        $roots = array();

        $from_config = $this->_get_librenms_base_from_weathermap_config();
        if ($from_config !== '') {
            $roots[] = $from_config;
        }

        foreach (array(getenv('LIBRENMS_ROOT'), getenv('LIBRENMS_BASE')) as $env_root) {
            if ($env_root !== FALSE && $env_root !== '') {
                $roots[] = $env_root;
            }
        }

        $roots[] = '/opt/librenms';

        // Walk upwards from the datasource file for custom installs.
        $dir = dirname(__FILE__);
        for ($i = 0; $i < 12; $i++) {
            if ($dir === '' || $dir === '/' || $dir === '.') {
                break;
            }
            $roots[] = $dir;
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        $clean = array();
        foreach ($roots as $root) {
            if ($root === NULL || $root === FALSE || $root === '') {
                continue;
            }
            $root = rtrim($root, '/');
            if ($root === '') {
                continue;
            }
            $real = realpath($root);
            if ($real !== FALSE) {
                $root = $real;
            }
            $clean[] = $root;
        }

        return array_values(array_unique($clean));
    }

    function _load_config(&$map)
    {
        if ($this->db_cfg !== NULL) {
            return $this->db_cfg;
        }

        $cfg = $this->_default_config();
        $loaded_from = 'environment/defaults';

        // Optional explicit INI remains supported if needed later.
        $ini_path = '';
        if (is_object($map) && method_exists($map, 'get_hint')) {
            $hint = $map->get_hint('librenms_db_config');
            if ($hint !== NULL && $hint !== '') {
                $ini_path = $hint;
            }
        }
        if ($ini_path === '' && getenv('LIBRENMS_DB_CONFIG') !== FALSE) {
            $ini_path = getenv('LIBRENMS_DB_CONFIG');
        }
        if ($ini_path !== '' && is_readable($ini_path)) {
            $ini = parse_ini_file($ini_path);
            if (is_array($ini)) {
                $cfg = $this->_merge_non_empty($cfg, $ini);
                $loaded_from = $ini_path;
            }
        }

        foreach ($this->_candidate_roots() as $root) {
            $env_path = rtrim($root, '/') . '/.env';
            if (is_readable($env_path)) {
                $cfg = $this->_merge_non_empty($cfg, $this->_parse_env_file($env_path));
                $loaded_from = $env_path;
                break;
            }
        }

        foreach ($this->_candidate_roots() as $root) {
            $legacy_path = rtrim($root, '/') . '/config.php';
            if (is_readable($legacy_path)) {
                $legacy = $this->_parse_legacy_config_php($legacy_path);
                if (count($legacy) > 0) {
                    $cfg = $this->_merge_non_empty($cfg, $legacy);
                    $loaded_from = $legacy_path;
                    break;
                }
            }
        }

        if ($cfg['host'] === '') {
            $cfg['host'] = 'localhost';
        }
        if ($cfg['port'] === '') {
            $cfg['port'] = '3306';
        }
        if ($cfg['database'] === '') {
            $cfg['database'] = 'librenms';
        }
        if ($cfg['username'] === '') {
            $cfg['username'] = 'librenms';
        }

        $safe_host = $cfg['host'];
        $safe_db = $cfg['database'];
        $safe_user = $cfg['username'];
        $safe_socket = isset($cfg['socket']) ? $cfg['socket'] : '';
        wm_debug("LibreNMSPortStatus: DB config loaded from $loaded_from; host=$safe_host db=$safe_db user=$safe_user socket=$safe_socket\n");

        $this->db_cfg = $cfg;
        return $cfg;
    }

    function _connect_db($cfg)
    {
        if ($this->db !== NULL) {
            return $this->db;
        }

        $port = intval($cfg['port']);
        $socket = isset($cfg['socket']) ? $cfg['socket'] : '';

        if ($this->db_driver === 'pdo') {
            try {
                if ($socket !== '') {
                    $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $cfg['database'] . ';charset=utf8mb4';
                } else {
                    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $port . ';dbname=' . $cfg['database'] . ';charset=utf8mb4';
                }

                $pdo = new PDO($dsn, $cfg['username'], $cfg['password']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->db = $pdo;
                return $this->db;
            } catch (Exception $e) {
                wm_warn('LibreNMSPortStatus: PDO DB connection failed: ' . $e->getMessage() . " [WMLNMSPORT02]\n");
                return NULL;
            }
        }

        $mysqli_socket = ($socket === '') ? NULL : $socket;
        $mysqli = @new mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database'], $port, $mysqli_socket);

        if ($mysqli->connect_errno) {
            wm_warn('LibreNMSPortStatus: mysqli DB connection failed: ' . $mysqli->connect_error . " [WMLNMSPORT03]\n");
            return NULL;
        }

        if (method_exists($mysqli, 'set_charset')) {
            $mysqli->set_charset('utf8mb4');
        }

        $this->db = $mysqli;
        return $this->db;
    }

    function _query_one($db, $sql, $params, $types)
    {
        if ($this->db_driver === 'pdo') {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row === FALSE) {
                    return NULL;
                }
                return $row;
            } catch (Exception $e) {
                wm_debug('LibreNMSPortStatus: PDO SQL failed: ' . $e->getMessage() . "\n");
                return NULL;
            }
        }

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            wm_debug('LibreNMSPortStatus: mysqli SQL prepare failed: ' . $db->error . "\n");
            return NULL;
        }

        if (count($params) > 0) {
            $bind = array($types);
            for ($i = 0; $i < count($params); $i++) {
                $bind[] = &$params[$i];
            }
            call_user_func_array(array($stmt, 'bind_param'), $bind);
        }

        if (!$stmt->execute()) {
            wm_debug('LibreNMSPortStatus: mysqli SQL execute failed: ' . $stmt->error . "\n");
            $stmt->close();
            return NULL;
        }

        // Avoid mysqli::get_result() so this also works when mysqlnd is not available.
        $meta = $stmt->result_metadata();
        if ($meta === FALSE) {
            wm_debug("LibreNMSPortStatus: mysqli result metadata unavailable.\n");
            $stmt->close();
            return NULL;
        }

        $row = array();
        $bind = array();
        $fields = $meta->fetch_fields();
        foreach ($fields as $field) {
            $row[$field->name] = NULL;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);

        if (!$stmt->fetch()) {
            $stmt->close();
            return NULL;
        }

        $stmt->close();
        return $row;
    }


    function _query_all_no_params($db, $sql)
    {
        $rows = array();

        if ($this->db_driver === 'pdo') {
            try {
                $stmt = $db->query($sql);
                if ($stmt === FALSE) {
                    return $rows;
                }
                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== FALSE) {
                    $rows[] = $row;
                }
                return $rows;
            } catch (Exception $e) {
                wm_debug('LibreNMSPortStatus: PDO SQL failed: ' . $e->getMessage() . "\n");
                return $rows;
            }
        }

        $result = $db->query($sql);
        if ($result === FALSE) {
            wm_debug('LibreNMSPortStatus: mysqli SQL failed: ' . $db->error . "\n");
            return $rows;
        }

        while (($row = $result->fetch_assoc()) !== NULL) {
            $rows[] = $row;
        }

        $result->free();
        return $rows;
    }

    function _ports_column_map($db)
    {
        if ($this->ports_columns !== NULL) {
            return $this->ports_columns;
        }

        $columns = array();
        $rows = $this->_query_all_no_params($db, 'SHOW COLUMNS FROM ports');

        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[strtolower($row['Field'])] = $row['Field'];
            }
        }

        $this->ports_columns = $columns;
        return $this->ports_columns;
    }

    function _get_warning_columns($db)
    {
        if ($this->warning_columns !== NULL) {
            return $this->warning_columns;
        }

        $map = $this->_ports_column_map($db);

        $wanted_delta = array(
            'ifInErrors_delta',
            'ifOutErrors_delta',
        );

        $wanted_raw = array(
            'ifInErrors',
            'ifOutErrors',
        );

        $delta = array();
        $raw = array();

        foreach ($wanted_delta as $column) {
            $key = strtolower($column);
            if (isset($map[$key])) {
                $delta[] = $map[$key];
            }
        }

        foreach ($wanted_raw as $column) {
            $key = strtolower($column);
            if (isset($map[$key])) {
                $raw[] = $map[$key];
            }
        }

        $this->warning_columns = array('delta' => $delta, 'raw' => $raw);

        wm_debug('LibreNMSPortStatus: error warning columns delta=' . implode(',', $delta) . ' raw=' . implode(',', $raw) . "\n");

        return $this->warning_columns;
    }

    function _sql_identifier($name)
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    function _extra_port_select_sql($db)
    {
        $warning = $this->_get_warning_columns($db);
        $columns = array();

        foreach ($warning['delta'] as $column) {
            $columns[$column] = TRUE;
        }
        foreach ($warning['raw'] as $column) {
            $columns[$column] = TRUE;
        }

        $sql = '';
        foreach (array_keys($columns) as $column) {
            $quoted = $this->_sql_identifier($column);
            $sql .= ', p.' . $quoted . ' AS ' . $quoted;
        }

        return $sql;
    }

    function _numeric_value($value)
    {
        if ($value === NULL || $value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return floatval($value);
        }
        return 0;
    }

    function _warning_info($row)
    {
        $warning = ($this->warning_columns !== NULL) ? $this->warning_columns : array('delta' => array(), 'raw' => array());
        $columns_to_check = array();
        $mode = 'none';

        // Prefer LibreNMS *_delta columns when present, because they represent new
        // errors since the previous poll. Raw counters are only a fallback.
        if (isset($warning['delta']) && count($warning['delta']) > 0) {
            $columns_to_check = $warning['delta'];
            $mode = 'delta';
        } elseif (isset($warning['raw']) && count($warning['raw']) > 0) {
            $columns_to_check = $warning['raw'];
            $mode = 'raw';
        }

        $total = 0;
        $details = array();

        foreach ($columns_to_check as $column) {
            if (isset($row[$column])) {
                $value = $this->_numeric_value($row[$column]);
                if ($value > 0) {
                    $details[] = $column . '=' . $value;
                    $total += $value;
                }
            }
        }

        return array(
            'has_warning' => ($total > 0),
            'mode' => $mode,
            'total' => $total,
            'details' => implode(', ', $details)
        );
    }

    function _status_value($row)
    {
        $oper = strtolower(trim((string)$row['ifOperStatus']));
        $admin = strtolower(trim((string)$row['ifAdminStatus']));

        // LibreNMS normally stores text values: up/down/lowerLayerDown/etc.
        // Numeric fallbacks are included for safety: 1=up, 2=down in IF-MIB.
        if ($admin === 'down' || $admin === 'disabled' || $admin === 'shutdown' || $admin === '2') {
            return 2;
        }

        if ($admin !== '' && $admin !== 'up' && $admin !== '1') {
            return 2;
        }

        if ($oper === 'up' || $oper === '1') {
            $warning = $this->_warning_info($row);
            if ($warning['has_warning']) {
                return 4;
            }
            return 1;
        }

        if ($oper === 'down' || $oper === '2' || $oper === 'lowerlayerdown' || $oper === '7' || $oper === 'notpresent' || $oper === '6' || $oper === 'dormant' || $oper === '5') {
            return 3;
        }

        return 0;
    }

    function _status_name($value)
    {
        if ($value == 1) {
            return 'up';
        }
        if ($value == 2) {
            return 'admin-down';
        }
        if ($value == 3) {
            return 'oper-down';
        }
        if ($value == 4) {
            return 'errors';
        }
        return 'unknown';
    }

    function _split_ifnames($ifname_text)
    {
        $result = array();
        $seen = array();
        $parts = explode(',', (string)$ifname_text);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $key = strtolower($part);
            if (!isset($seen[$key])) {
                $result[] = $part;
                $seen[$key] = TRUE;
            }
        }

        return $result;
    }

    function _graph_url_for_port_id($port_id)
    {
        return '/graph.php?height=100&width=512&id=' . rawurlencode((string)$port_id) . '&type=port_bits&legend=no&title=yes';
    }

    function _add_graph_url_hints($item, $overlibgraphs)
    {
        if (!is_object($item) || !method_exists($item, 'add_hint')) {
            return;
        }

        if (!is_array($overlibgraphs) || count($overlibgraphs) == 0) {
            return;
        }

        // Backwards-compatible single string for display/debug only.
        // Do not use this as the only OVERLIBGRAPH token for multiple graphs.
        $item->add_hint('librenms_overlibgraph', implode(' ', $overlibgraphs));

        // Fixed placeholder hints used by multi graph templates.
        // Fallback to graph 1 for missing graph slots to avoid unresolved tokens
        // causing broken img src values if the wrong multi template is used.
        $first = $overlibgraphs[0];
        for ($i = 1; $i <= 10; $i++) {
            $idx = $i - 1;
            $url = isset($overlibgraphs[$idx]) ? $overlibgraphs[$idx] : $first;
            $item->add_hint('librenms_graph_url_' . $i, $url);
        }
    }

    function _select_primary_row($rows)
    {
        if (is_array($rows) && count($rows) > 0) {
            return $rows[0];
        }
        return NULL;
    }

    function _aggregate_status_value($rows)
    {
        if (!is_array($rows) || count($rows) == 0) {
            return 0;
        }

        $values = array();
        foreach ($rows as $row) {
            $values[] = $this->_status_value($row);
        }

        // Worst-status order for physical + subinterfaces:
        // red wins first, then orange/errors, then all-admin-down amber,
        // then green if at least one found interface is clean/up.
        if (in_array(3, $values)) {
            return 3;
        }
        if (in_array(4, $values)) {
            return 4;
        }

        $all_admin_down = TRUE;
        foreach ($values as $value) {
            if ($value != 2) {
                $all_admin_down = FALSE;
                break;
            }
        }
        if ($all_admin_down) {
            return 2;
        }

        if (in_array(1, $values)) {
            return 1;
        }
        if (in_array(2, $values)) {
            return 2;
        }

        return 0;
    }

    function _aggregate_warning_details($rows)
    {
        $details = array();

        foreach ($rows as $row) {
            $warning = $this->_warning_info($row);
            if ($warning['has_warning']) {
                $ifname = isset($row['ifName']) ? $row['ifName'] : 'unknown-interface';
                $details[] = $ifname . ' ' . $warning['details'];
            }
        }

        return implode('; ', $details);
    }

    function _aggregate_warning_total($rows)
    {
        $total = 0;

        foreach ($rows as $row) {
            $warning = $this->_warning_info($row);
            $total += $warning['total'];
        }

        return $total;
    }

    function _add_item_hints_for_rows($item, $rows, $value, $requested_ifnames, $missing_ifnames)
    {
        if (!is_object($item) || !method_exists($item, 'add_hint')) {
            return;
        }

        $primary = $this->_select_primary_row($rows);
        if ($primary === NULL) {
            $item->add_hint('librenms_status_value', 0);
            $item->add_hint('librenms_status_name', 'unknown');
            $item->add_hint('librenms_found_count', 0);
            $item->add_hint('librenms_missing_ifnames', implode(',', $missing_ifnames));
            $item->add_hint('librenms_requested_ifnames', implode(',', $requested_ifnames));
            return;
        }

        $port_ids = array();
        $ifnames = array();
        $ifdescrs = array();
        $ifaliases = array();
        $overlibgraphs = array();
        $oper_statuses = array();
        $admin_statuses = array();

        foreach ($rows as $row) {
            $port_ids[] = $row['port_id'];
            $ifnames[] = $row['ifName'];
            $ifdescrs[] = $row['ifDescr'];
            $ifaliases[] = $row['ifAlias'];
            $oper_statuses[] = $row['ifName'] . '=' . $row['ifOperStatus'];
            $admin_statuses[] = $row['ifName'] . '=' . $row['ifAdminStatus'];
            $overlibgraphs[] = $this->_graph_url_for_port_id($row['port_id']);
        }

        $warning_total = $this->_aggregate_warning_total($rows);
        $warning_details = $this->_aggregate_warning_details($rows);

        $item->add_hint('librenms_port_id', $primary['port_id']);
        $item->add_hint('librenms_port_ids', implode(',', $port_ids));
        $item->add_hint('librenms_graph_count', count($overlibgraphs));
        $this->_add_graph_url_hints($item, $overlibgraphs);
        $item->add_hint('librenms_ifOperStatus', $primary['ifOperStatus']);
        $item->add_hint('librenms_ifAdminStatus', $primary['ifAdminStatus']);
        $item->add_hint('librenms_ifOperStatus_all', implode(', ', $oper_statuses));
        $item->add_hint('librenms_ifAdminStatus_all', implode(', ', $admin_statuses));
        $item->add_hint('librenms_hostname', $primary['hostname']);
        $item->add_hint('librenms_sysName', $primary['sysName']);
        $item->add_hint('librenms_ip', $primary['ip']);
        $item->add_hint('librenms_ifName', implode(',', $ifnames));
        $item->add_hint('librenms_ifDescr', implode(',', $ifdescrs));
        $item->add_hint('librenms_ifAlias', implode(',', $ifaliases));
        $item->add_hint('librenms_status_value', $value);
        $item->add_hint('librenms_status_name', $this->_status_name($value));
        $item->add_hint('librenms_warning_mode', 'delta');
        $item->add_hint('librenms_warning_total', $warning_total);
        $item->add_hint('librenms_warning_details', $warning_details);
        $item->add_hint('librenms_found_count', count($rows));
        $item->add_hint('librenms_missing_ifnames', implode(',', $missing_ifnames));
        $item->add_hint('librenms_requested_ifnames', implode(',', $requested_ifnames));
    }

    function _add_item_hints($item, $row, $value)
    {
        if (!is_object($item) || !method_exists($item, 'add_hint')) {
            return;
        }

        $warning = $this->_warning_info($row);

        $item->add_hint('librenms_port_id', $row['port_id']);
        $item->add_hint('librenms_port_ids', $row['port_id']);
        $item->add_hint('librenms_graph_count', 1);
        $this->_add_graph_url_hints($item, array($this->_graph_url_for_port_id($row['port_id'])));
        $item->add_hint('librenms_ifOperStatus', $row['ifOperStatus']);
        $item->add_hint('librenms_ifAdminStatus', $row['ifAdminStatus']);
        $item->add_hint('librenms_hostname', $row['hostname']);
        $item->add_hint('librenms_sysName', $row['sysName']);
        $item->add_hint('librenms_ip', $row['ip']);
        $item->add_hint('librenms_ifName', $row['ifName']);
        $item->add_hint('librenms_ifDescr', $row['ifDescr']);
        $item->add_hint('librenms_ifAlias', $row['ifAlias']);
        $item->add_hint('librenms_status_value', $value);
        $item->add_hint('librenms_status_name', $this->_status_name($value));
        $item->add_hint('librenms_warning_mode', $warning['mode']);
        $item->add_hint('librenms_warning_total', $warning['total']);
        $item->add_hint('librenms_warning_details', $warning['details']);
    }

    function _read_by_port_id($db, $port_id)
    {
        $extra_select = $this->_extra_port_select_sql($db);
        $sql = "SELECT p.port_id, p.ifName, p.ifDescr, p.ifAlias, p.ifOperStatus, p.ifAdminStatus, d.hostname, d.sysName, d.ip" . $extra_select . "
                FROM ports p
                JOIN devices d ON d.device_id = p.device_id
                WHERE p.port_id = ?
                LIMIT 1";

        return $this->_query_one($db, $sql, array(intval($port_id)), 'i');
    }

    function _read_by_device_ifname($db, $device, $ifname)
    {
        $device_like = $device . '.%';
        $extra_select = $this->_extra_port_select_sql($db);

        $sql = "SELECT p.port_id, p.ifName, p.ifDescr, p.ifAlias, p.ifOperStatus, p.ifAdminStatus, d.hostname, d.sysName, d.ip" . $extra_select . "
                FROM ports p
                JOIN devices d ON d.device_id = p.device_id
                WHERE (
                       d.hostname = ?
                    OR d.sysName = ?
                    OR d.ip = ?
                    OR d.hostname LIKE ?
                    OR d.sysName LIKE ?
                )
                  AND (
                       p.ifName = ?
                    OR p.ifDescr = ?
                    OR p.ifAlias = ?
                )
                ORDER BY p.port_id ASC
                LIMIT 1";

        return $this->_query_one(
            $db,
            $sql,
            array($device, $device, $device, $device_like, $device_like, $ifname, $ifname, $ifname),
            'ssssssss'
        );
    }

    function _read_by_device_ifnames($db, $device, $ifname_text)
    {
        $requested = $this->_split_ifnames($ifname_text);
        $rows = array();
        $missing = array();
        $seen_ports = array();

        foreach ($requested as $ifname) {
            $row = $this->_read_by_device_ifname($db, $device, $ifname);
            if ($row) {
                $port_key = (string)$row['port_id'];
                if (!isset($seen_ports[$port_key])) {
                    $rows[] = $row;
                    $seen_ports[$port_key] = TRUE;
                }
            } else {
                $missing[] = $ifname;
            }
        }

        return array('rows' => $rows, 'requested' => $requested, 'missing' => $missing);
    }

    function ReadData($targetstring, &$map, &$item)
    {
        $data_time = time();

        if (isset($this->status_cache[$targetstring])) {
            $cached = $this->status_cache[$targetstring];
            if (isset($cached['rows']) && isset($cached['value'])) {
                $this->_add_item_hints_for_rows(
                    $item,
                    $cached['rows'],
                    $cached['value'],
                    isset($cached['requested_ifnames']) ? $cached['requested_ifnames'] : array(),
                    isset($cached['missing_ifnames']) ? $cached['missing_ifnames'] : array()
                );
                return array($cached['value'], $cached['value'], $data_time);
            }
            if (isset($cached['row']) && isset($cached['value'])) {
                $this->_add_item_hints($item, $cached['row'], $cached['value']);
                return array($cached['value'], $cached['value'], $data_time);
            }
            return array($cached, $cached, $data_time);
        }

        $cfg = $this->_load_config($map);
        $db = $this->_connect_db($cfg);

        if ($db === NULL) {
            wm_debug("LibreNMSPortStatus: DB connection unavailable for target $targetstring\n");
            return array(0, 0, $data_time);
        }

        $rows = array();
        $requested_ifnames = array();
        $missing_ifnames = array();

        if (preg_match('/^librenmsportstatus:(\d+)$/', $targetstring, $matches)) {
            $row = $this->_read_by_port_id($db, intval($matches[1]));
            if ($row) {
                $rows[] = $row;
                $requested_ifnames[] = $row['ifName'];
            }
        } elseif (preg_match('/^librenmsportstatus:([^:]+):(.+)$/', $targetstring, $matches)) {
            $lookup = $this->_read_by_device_ifnames($db, $matches[1], $matches[2]);
            $rows = $lookup['rows'];
            $requested_ifnames = $lookup['requested'];
            $missing_ifnames = $lookup['missing'];
        }

        if (count($rows) > 0) {
            $value = $this->_aggregate_status_value($rows);
            $this->status_cache[$targetstring] = array(
                'value' => $value,
                'rows' => $rows,
                'requested_ifnames' => $requested_ifnames,
                'missing_ifnames' => $missing_ifnames
            );
            $this->_add_item_hints_for_rows($item, $rows, $value, $requested_ifnames, $missing_ifnames);

            $port_ids = array();
            $ifnames = array();
            foreach ($rows as $row) {
                $port_ids[] = $row['port_id'];
                $ifnames[] = $row['ifName'];
            }

            $warning_details = $this->_aggregate_warning_details($rows);
            $warning_text = '';
            if ($warning_details !== '') {
                $warning_text = ', warning_details=' . $warning_details;
            }
            $missing_text = '';
            if (count($missing_ifnames) > 0) {
                $missing_text = ', missing_ifnames=' . implode(',', $missing_ifnames);
            }

            wm_debug('LibreNMSPortStatus: ' . $targetstring . ' -> value=' . $value . ', status=' . $this->_status_name($value) . ', ports=' . implode(',', $port_ids) . ', ifNames=' . implode(',', $ifnames) . ', graph_count=' . count($rows) . $warning_text . $missing_text . "\n");
            return array($value, $value, $data_time);
        }

        wm_debug("LibreNMSPortStatus: no LibreNMS port found for target $targetstring\n");
        return array(0, 0, $data_time);
    }

}

// vim:ts=4:sw=4:
?>
