<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproBoard extends CommonDBTM {

    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return _n('Quadro', 'Quadros', $nb, 'kanpro');
    }

    static function getMenuName() {
        return 'Quadros';
    }

    static function getMenuContent() {
        $menu = parent::getMenuContent();
        $menu['icon'] = 'ti ti-layout-kanban';
        $menu['title'] = 'Quadros';
        $menu['page']  = '/plugins/kanpro/front/board.php';
        return $menu;
    }

    static function canView(): bool {
        return Session::haveRight(self::$rightname, READ);
    }
    static function canCreate(): bool {
        return Session::haveRight(self::$rightname, CREATE);
    }
    public function canViewItem(): bool {
        return Session::haveRight(self::$rightname, READ);
    }
    public function canCreateItem(): bool {
        return Session::haveRight(self::$rightname, CREATE);
    }
    public function canUpdateItem(): bool {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    function defineTabs($options = []) {
        $ong = [];
        $this->addDefaultFormTab($ong);
        return $ong;
    }

    function prepareInputForAdd($input) {
        $input['users_id'] = $input['users_id'] ?? Session::getLoginUserID();
        if (empty($input['name'])) {
            Session::addMessageAfterRedirect(__('Nome obrigatório', 'kanpro'), false, ERROR);
            return false;
        }
        $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $input['date_mod'] = $input['date_creation'];
        $input['color'] = $input['color'] ?? '#0079bf';
        if (isset($input['color']) && strlen($input['color']) > 255) $input['color'] = substr($input['color'], 0, 255);
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = $_SESSION['glpiactive_entity'] ?? 0;
        }
        $input['generate_term'] = !empty($input['generate_term']) ? 1 : 0;
        return $input;
    }

    function prepareInputForUpdate($input) {
        $input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if (isset($input['color']) && strlen($input['color']) > 255) $input['color'] = substr($input['color'], 0, 255);
        if (array_key_exists('generate_term', $input) || isset($input['_glpi_csrf_token'])) {
            // formulário de edição sempre envia o form completo, então se o checkbox não veio, é porque foi desmarcado
            $input['generate_term'] = !empty($input['generate_term']) ? 1 : 0;
        }
        return $input;
    }

    function post_addItem() {
        global $DB;
        // cria etiquetas padrão Trello
        $defaults = [
            ['name' => '', 'color' => '#61bd4f'],
            ['name' => '', 'color' => '#f2d600'],
            ['name' => '', 'color' => '#ff9f1a'],
            ['name' => '', 'color' => '#eb5a46'],
            ['name' => '', 'color' => '#c377e0'],
            ['name' => '', 'color' => '#0079bf'],
        ];
        foreach ($defaults as $l) {
            $DB->insert('glpi_plugin_kanpro_labels', [
                'plugin_kanpro_boards_id' => $this->getID(),
                'name'  => $l['name'],
                'color' => $l['color'],
            ]);
        }
        // cria listas padrão
        $lists = ['A Fazer', 'Em Progresso', 'Concluído'];
        $rank = 1000;
        foreach ($lists as $lname) {
            $list = new PluginKanproList();
            $list->add([
                'plugin_kanpro_boards_id' => $this->getID(),
                'name' => $lname,
                'rank' => $rank,
            ]);
            $rank += 1000;
        }
        // adiciona criador como membro admin
        $DB->insert('glpi_plugin_kanpro_boards_members', [
            'plugin_kanpro_boards_id' => $this->getID(),
            'users_id' => Session::getLoginUserID(),
            'role' => 'admin',
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        self::logActivity($this->getID(), null, null, 'board_create', 'Quadro criado');
    }

    function cleanDBonPurge() {
        global $DB;
        $bid = $this->getID();
        // remove imagem de fundo do quadro
        $bg = $this->fields['background'] ?? null;
        if (empty($bg)) {
            $row = $DB->request(['SELECT'=>['background'],'FROM'=>'glpi_plugin_kanpro_boards','WHERE'=>['id'=>$bid]])->current();
            $bg = $row['background'] ?? null;
        }
        if (!empty($bg)) {
            $path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $bg;
            if (is_file($path)) @unlink($path);
            $dir = dirname($path);
            if (is_dir($dir) && count(glob($dir.'/*'))===0) @rmdir($dir);
        }
        // cascata: listas -> cartões -> tudo
        $lists = $DB->request(['FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['plugin_kanpro_boards_id' => $bid]]);
        foreach ($lists as $l) {
            $obj = new PluginKanproList();
            $obj->delete(['id' => $l['id']], true);
        }
        $DB->delete('glpi_plugin_kanpro_labels', ['plugin_kanpro_boards_id' => $bid]);
        $DB->delete('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => $bid]);
        $DB->delete('glpi_plugin_kanpro_activities', ['plugin_kanpro_boards_id' => $bid]);
        // anexos são apagados via card purge
    }

    static function logActivity($boards_id, $cards_id = null, $lists_id = null, $action = '', $details = '') {
        global $DB;
        $DB->insert('glpi_plugin_kanpro_activities', [
            'plugin_kanpro_boards_id' => $boards_id,
            'plugin_kanpro_cards_id'  => $cards_id,
            'plugin_kanpro_lists_id'  => $lists_id,
            'users_id'   => Session::getLoginUserID(),
            'action'     => $action,
            'details'    => $details,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    // Cores de fundo Trello-like — paleta expandida
    static function getBackgroundColors(): array {
        return [
            '#0079bf' => 'Azul Clássico',
            '#00aecc' => 'Ciano',
            '#0091a8' => 'Teal',
            '#00875a' => 'Verde Esmeralda',
            '#4bbf6b' => 'Verde Claro',
            '#61bd4f' => 'Verde Trello',
            '#519839' => 'Verde Escuro',
            '#7bc86c' => 'Menta',
            '#d29034' => 'Laranja Queimado',
            '#ff9f1a' => 'Laranja Vivo',
            '#ff7a3d' => 'Laranja Avermelhado',
            '#f2d600' => 'Amarelo Sol',
            '#ffcc02' => 'Amarelo Ouro',
            '#ff7452' => 'Coral',
            '#eb5a46' => 'Vermelho Claro',
            '#b04632' => 'Vermelho Tijolo',
            '#c377e0' => 'Roxo Lavanda',
            '#9c6ade' => 'Roxo Médio',
            '#6554c0' => 'Roxo Profundo',
            '#ff78cb' => 'Rosa Chiclete',
            '#e1316f' => 'Rosa Forte',
            '#344563' => 'Grafite',
            '#172b4d' => 'Azul Marinho',
            '#091e42' => 'Azul Noite',
            '#6b778c' => 'Cinza Neutro',
            '#2c3e50' => 'Cinza Azulado',
        ];
    }

    // Degradês / temas prontos (gradientes CSS)
    static function getBackgroundGradients(): array {
        return [
            'linear-gradient(135deg, #0079bf 0%, #00d2ff 100%)' => 'Oceano',
            'linear-gradient(135deg, #61bd4f 0%, #00aecc 100%)' => 'Floresta Tropical',
            'linear-gradient(135deg, #ff9f1a 0%, #eb5a46 100%)' => 'Pôr do Sol',
            'linear-gradient(135deg, #6554c0 0%, #ff78cb 100%)' => 'Aurora Roxa',
            'linear-gradient(135deg, #344563 0%, #091e42 100%)' => 'Noite Profunda',
            'linear-gradient(135deg, #b04632 0%, #ffab00 100%)' => 'Vulcão',
            'linear-gradient(135deg, #006064 0%, #00b8d9 100%)' => 'Ártico',
            'linear-gradient(135deg, #d29034 0%, #f2d600 100%)' => 'Deserto Dourado',
            'linear-gradient(135deg, #00875a 0%, #57d9a3 100%)' => 'Selva',
            'linear-gradient(135deg, #e1316f 0%, #ff7452 100%)' => 'Magenta Flame',
            'linear-gradient(135deg, #091e42 0%, #6554c0 100%)' => 'Galáxia',
            'linear-gradient(135deg, #172b4d 0%, #00aecc 100%)' => 'Boreal',
        ];
    }

    // Retorna todos os temas (sólidos + degradês) — útil para JS
    static function getBoardThemes(): array {
        return [
            'solids'    => self::getBackgroundColors(),
            'gradients' => self::getBackgroundGradients(),
        ];
    }

    // === Background por imagem ===
    static function getBackgroundImageUrl(int $boards_id, ?string $background = null): string {
        if (empty($background)) return '';
        // background armazena caminho relativo tipo boards/12/bg_xxx.jpg
        // servido via front/background.php com cache-bust via hash
        return Plugin::getWebDir('kanpro') . '/front/background.php?boards_id=' . $boards_id . '&v=' . substr(md5($background), 0, 6);
    }

    static function getBackgroundStyle(array $board): string {
        $color = $board['color'] ?? '#0079bf';
        $bg = $board['background'] ?? null;
        if (!empty($bg)) {
            $bid = (int)($board['id'] ?? 0);
            if ($bid) {
                $url = self::getBackgroundImageUrl($bid, $bg);
                // imagem com fallback da cor; cover centralizado
                return "url('" . $url . "') center / cover no-repeat, " . $color;
            }
        }
        return $color;
    }

    static function handleBackgroundUpload(int $boards_id, array $file): ?string {
        if (empty($boards_id) || empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $allowedMimes = ['image/jpeg','image/png','image/webp','image/gif'];
        $allowedExts = ['jpg','jpeg','png','webp','gif'];
        $maxBytes = 5 * 1024 * 1024; // 5MB
        if (($file['size'] ?? 0) > $maxBytes) {
            Session::addMessageAfterRedirect(__('Imagem muito grande — máximo 5MB', 'kanpro'), false, ERROR);
            return null;
        }
        $mime = $file['type'] ?? '';
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true) || (!empty($mime) && !in_array($mime, $allowedMimes, true) && strpos($mime, 'image/') !== 0)) {
            Session::addMessageAfterRedirect(__('Formato inválido — use JPG, PNG, WebP ou GIF', 'kanpro'), false, ERROR);
            return null;
        }
        // valida dimensões mínimas se possível (opcional)
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp && function_exists('getimagesize')) {
            $info = @getimagesize($tmp);
            if ($info) {
                [$w,$h] = $info;
                if ($w < 800 || $h < 450) {
                    Session::addMessageAfterRedirect(__('Imagem muito pequena — recomendado mínimo 1280×720', 'kanpro'), false, WARNING);
                }
            }
        }
        $dir = GLPI_PLUGIN_DOC_DIR . '/kanpro/boards/' . $boards_id . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Session::addMessageAfterRedirect(__('Falha ao criar diretório de upload', 'kanpro'), false, ERROR);
            return null;
        }
        // remove imagem antiga do mesmo quadro (evita acúmulo)
        $old = null;
        global $DB;
        if ($DB->tableExists('glpi_plugin_kanpro_boards')) {
            $row = $DB->request(['SELECT'=>['background'],'FROM'=>'glpi_plugin_kanpro_boards','WHERE'=>['id'=>$boards_id]])->current();
            $old = $row['background'] ?? null;
            if (!empty($old)) {
                $oldPath = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $old;
                if (is_file($oldPath)) @unlink($oldPath);
            }
        }
        $safeExt = in_array($ext, $allowedExts, true) ? $ext : 'jpg';
        $filename = 'bg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
        $dest = $dir . $filename;
        $moved = false;
        if (is_uploaded_file($tmp)) {
            $moved = @move_uploaded_file($tmp, $dest);
        }
        if (!$moved) {
            $moved = @copy($tmp, $dest);
        }
        if (!$moved || !is_file($dest)) {
            Session::addMessageAfterRedirect(__('Falha ao salvar imagem', 'kanpro'), false, ERROR);
            return null;
        }
        $relative = 'boards/' . $boards_id . '/' . $filename;
        // salva no DB
        if ($DB->tableExists('glpi_plugin_kanpro_boards')) {
            $DB->update('glpi_plugin_kanpro_boards', ['background'=>$relative,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$boards_id]);
        }
        return $relative;
    }

    static function deleteBackgroundFile(int $boards_id, ?string $old = null): void {
        global $DB;
        if (empty($old) && $DB->tableExists('glpi_plugin_kanpro_boards')) {
            $row = $DB->request(['SELECT'=>['background'],'FROM'=>'glpi_plugin_kanpro_boards','WHERE'=>['id'=>$boards_id]])->current();
            $old = $row['background'] ?? null;
        }
        if (!empty($old)) {
            $path = GLPI_PLUGIN_DOC_DIR . '/kanpro/' . $old;
            if (is_file($path)) @unlink($path);
            // limpa diretório se vazio
            $dir = dirname($path);
            if (is_dir($dir) && count(glob($dir.'/*'))===0) @rmdir($dir);
        }
        if ($DB->tableExists('glpi_plugin_kanpro_boards')) {
            $DB->update('glpi_plugin_kanpro_boards', ['background'=>null,'date_mod'=>date('Y-m-d H:i:s')], ['id'=>$boards_id]);
        }
    }

    function showForm($ID, array $options = []) {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $canedit = $this->canUpdateItem();
        $is_new  = ($ID <= 0);

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='name'>Nome do Quadro *</label></td>";
        echo "<td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40, 'required' => true]);
        echo "</td>";

        echo "<td>Cor / Tema de Fundo</td><td>";
        $colors = self::getBackgroundColors();
        $gradients = self::getBackgroundGradients();
        $current = $this->fields['color'] ?? '#0079bf';
        $currentEsc = htmlspecialchars($current, ENT_QUOTES);
        $isHex = preg_match('/^#[0-9a-fA-F]{6}$/', $current);
        $customDefault = $isHex ? $current : '#0079bf';
        $customDefaultEsc = htmlspecialchars($customDefault, ENT_QUOTES);
        // input oculto que realmente envia a cor/tema (hex ou gradient CSS)
        echo "<input type='hidden' name='color' id='kanpro-color-input' value='{$currentEsc}'>";
        // compat: mantém color_custom oculto para fallback mas sincronizado via JS
        echo "<input type='hidden' name='color_custom' id='kanpro-color-custom' value='{$customDefaultEsc}'>";
        echo "<div id='kanpro-color-picker-wrap' style='max-width:560px'>";
        // Secao cores solidas
        echo "<div style='font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin-bottom:6px'>CORES SÓLIDAS</div>";
        echo "<div id='kanpro-solids' style='display:flex;gap:6px;flex-wrap:wrap;align-items:center'>";
        foreach ($colors as $hex => $label) {
            $hexEsc = htmlspecialchars($hex, ENT_QUOTES);
            $labelEsc = htmlspecialchars($label, ENT_QUOTES);
            echo "<span class='kanpro-color-opt' data-value=\"{$hexEsc}\" title=\"{$labelEsc}\" style='width:34px;height:34px;border-radius:6px;background:{$hexEsc};border:2px solid transparent;box-shadow:0 1px 3px rgba(0,0,0,.15);cursor:pointer;display:inline-block;flex-shrink:0;position:relative;transition:transform .12s,box-shadow .12s,border-color .12s'></span>";
        }
        echo "</div>";
        // Secao degradês
        echo "<div style='font-size:11px;font-weight:700;color:#5e6c84;letter-spacing:.04em;margin:10px 0 6px'>DEGRADÊS — TEMAS</div>";
        echo "<div id='kanpro-gradients' style='display:flex;gap:6px;flex-wrap:wrap;align-items:center'>";
        foreach ($gradients as $grad => $label) {
            $gradEsc = htmlspecialchars($grad, ENT_QUOTES);
            $labelEsc = htmlspecialchars($label, ENT_QUOTES);
            echo "<span class='kanpro-color-opt' data-value=\"{$gradEsc}\" title=\"{$labelEsc}\" style='width:74px;height:34px;border-radius:6px;background:{$gradEsc};border:2px solid transparent;box-shadow:0 1px 3px rgba(0,0,0,.15);cursor:pointer;display:inline-block;flex-shrink:0;position:relative;transition:transform .12s,box-shadow .12s,border-color .12s'></span>";
        }
        echo "</div>";
        // Custom picker + preview
        echo "<div style='margin-top:12px;display:flex;align-items:center;gap:10px;padding:10px;background:#f4f5f7;border-radius:8px;border:1px solid #dfe1e6'>";
        echo "<input type='color' id='kanpro-custom-picker' value='{$customDefaultEsc}' style='width:44px;height:34px;border:none;padding:0;border-radius:6px;cursor:pointer;flex-shrink:0'>";
        echo "<div style='flex:1;min-width:0'>";
        echo "<div style='font-size:12px;font-weight:700;color:#172b4d'>Cor personalizada</div>";
        echo "<small style='color:#6b778c'>Escolha qualquer cor — clica para aplicar</small>";
        echo "</div>";
        echo "<span id='kanpro-current-preview' style='width:40px;height:34px;border-radius:6px;border:2px solid #dfe1e6;display:inline-block;flex-shrink:0;background:{$currentEsc};box-shadow: inset 0 0 0 1px rgba(0,0,0,.08)'></span>";
        echo "</div>";
        echo "<div style='margin-top:8px;font-size:11px;color:#5e6c84;display:flex;align-items:center;gap:6px'><span id='kanpro-selected-label' style='font-weight:700;color:#172b4d;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block'>{$currentEsc}</span> <span>• clique numa cor para selecionar</span></div>";
        echo "</div>";
        // JS para seleção visual
        echo "<script>
        (function(){
          const input = document.getElementById('kanpro-color-input');
          const customHidden = document.getElementById('kanpro-color-custom');
          const preview = document.getElementById('kanpro-current-preview');
          const labelEl = document.getElementById('kanpro-selected-label');
          const customPicker = document.getElementById('kanpro-custom-picker');
          if(!input) return;
          function updateSelection(value, label){
            input.value = value;
            if(customHidden) customHidden.value = value;
            if(preview) preview.style.background = value;
            if(labelEl) labelEl.textContent = label || value;
            // sincroniza picker se for hex
            if(customPicker && /^#[0-9a-fA-F]{6}\$/.test(value)){
              customPicker.value = value;
            }
            document.querySelectorAll('.kanpro-color-opt').forEach(el=>{
              const v = el.dataset.value;
              const isSel = v === value;
              el.style.border = isSel ? '3px solid #172b4d' : '2px solid transparent';
              el.style.transform = isSel ? 'scale(1.06)' : 'scale(1)';
              el.style.boxShadow = isSel ? '0 3px 10px rgba(9,30,66,.25)' : '0 1px 3px rgba(0,0,0,.15)';
              let check = el.querySelector('.kanpro-check');
              if(!check){
                check = document.createElement('span');
                check.className='kanpro-check';
                check.textContent='✓';
                check.style.cssText='position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:15px;text-shadow:0 1px 3px rgba(0,0,0,.5);pointer-events:none;';
                el.style.position='relative';
                el.appendChild(check);
              }
              check.style.display = isSel ? 'flex' : 'none';
              // contraste para cores claras
              if(isSel && (value.toLowerCase()==='#f2d600' || value.toLowerCase()==='#ffcc02' || value.toLowerCase()==='#f2d600')){
                check.style.color='#172b4d';
                check.style.textShadow='0 1px 0 rgba(255,255,255,.7)';
              } else if(isSel){
                check.style.color='#fff';
                check.style.textShadow='0 1px 3px rgba(0,0,0,.5)';
              }
            });
          }
          window._kanproUpdateColor = updateSelection;
          document.querySelectorAll('.kanpro-color-opt').forEach(el=>{
            el.addEventListener('click', ()=> updateSelection(el.dataset.value, el.title));
          });
          if(customPicker){
            customPicker.addEventListener('input', ()=>{
              const v = customPicker.value;
              updateSelection(v, 'Personalizada ' + v);
            });
            customPicker.addEventListener('change', ()=>{
              const v = customPicker.value;
              updateSelection(v, 'Personalizada ' + v);
            });
          }
          // inicializa
          let initVal = input.value || '#0079bf';
          let initLabel = initVal;
          let foundLabel = null;
          document.querySelectorAll('.kanpro-color-opt').forEach(el=>{ if(el.dataset.value===initVal) foundLabel = el.title; });
          if(foundLabel) initLabel = foundLabel; else if(initVal.includes('gradient')) initLabel = 'Degradê personalizado'; else if(/^#[0-9a-fA-F]{6}\$/.test(initVal)) initLabel = 'Personalizada ' + initVal;
          updateSelection(initVal, initLabel);
        })();
        </script>";
        echo "</td></tr>";

        // === Imagem de fundo ===
        $bg = $this->fields['background'] ?? '';
        $bgUrl = '';
        $bgPreview = '';
        if (!empty($bg) && $ID > 0) {
            $bgUrl = self::getBackgroundImageUrl($ID, $bg);
            $bgPreview = "<div style='margin-bottom:10px;display:flex;align-items:center;gap:12px'><img src='" . htmlspecialchars($bgUrl, ENT_QUOTES) . "' style='width:180px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #dfe1e6;box-shadow:0 1px 4px rgba(0,0,0,.15)'><div style='flex:1'><div style='font-size:12px;font-weight:700;color:#172b4d'>Imagem atual</div><div style='font-size:11px;color:#5e6c84;word-break:break-all'>" . htmlspecialchars($bg, ENT_QUOTES) . "</div><label style='display:flex;align-items:center;gap:6px;margin-top:6px;cursor:pointer;color:#bf2600;font-size:12px'><input type='checkbox' name='remove_background' value='1'> Remover imagem (volta para cor/degradê)</label></div></div>";
        }
        echo "<tr class='tab_bg_1'>";
        echo "<td>Imagem de Fundo<br><small style='color:#6b778c'>Tema com foto</small></td>";
        echo "<td colspan='3'>";
        echo $bgPreview;
        echo "<div style='display:flex;flex-direction:column;gap:8px'>";
        echo "<label style='display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid #dfe1e6;padding:8px 12px;border-radius:6px;cursor:pointer;width:fit-content'><i class='ti ti-photo' style='color:#6554c0'></i> Escolher imagem <input type='file' name='background_image' accept='image/jpeg,image/png,image/webp,image/gif' style='display:none' onchange=\"const f=this.files[0]; const p=document.getElementById('kanpro-bg-file-preview'); const n=document.getElementById('kanpro-bg-file-name'); if(f){ n.textContent=f.name+' ('+(f.size/1024/1024).toFixed(2)+' MB)'; if(p){ p.style.display='block'; p.src=URL.createObjectURL(f); } }\"> </label>";
        echo "<span id='kanpro-bg-file-name' style='font-size:11px;color:#5e6c84'></span>";
        echo "<img id='kanpro-bg-file-preview' style='display:none;width:320px;max-width:100%;height:180px;object-fit:cover;border-radius:8px;border:1px solid #dfe1e6'>";
        echo "<div style='background:#f4f5f7;border:1px solid #dfe1e6;border-radius:6px;padding:10px;font-size:11px;color:#5e6c84;line-height:1.5'>";
        echo "<strong style='color:#172b4d'><i class='ti ti-info-circle'></i> Resolução recomendada:</strong> <strong>1920×1080 (Full HD, 16:9)</strong> — mínimo <strong>1280×720</strong>. Ideal para 4K: <strong>2560×1440</strong>.<br>";
        echo "Formatos: <strong>JPG, PNG, WebP, GIF</strong> • Tamanho máx: <strong>5 MB</strong> (ideal &lt; 2 MB).<br>";
        echo "Exibição: <code style='background:#fff;padding:1px 4px;border-radius:4px;border:1px solid #dfe1e6'>background-size: cover</code> centralizada (<code>center / cover no-repeat</code>) — a imagem preenche todo o fundo e corta bordas se necessário, sem distorcer.<br>";
        echo "<span style='color:#6b778c'>Dica: use imagem horizontal com ponto focal no centro. Evite textos nas bordas.</span>";
        echo "</div>";
        echo "</div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>Entidade</td><td>";
        Entity::dropdown(['value' => $this->fields['entities_id'] ?? $_SESSION['glpiactive_entity'], 'entity' => $_SESSION['glpiactiveentities'] ?? [0]]);
        echo "</td>";
        echo "<td>Visibilidade</td><td>";
        Dropdown::showFromArray('visibility', [
            'private' => '🔒 Privado',
            'team'    => '👥 Equipe',
            'public'  => '🌐 Público',
        ], ['value' => $this->fields['visibility'] ?? 'private']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>Descrição</td><td colspan='3'>";
        echo "<textarea name='comment' rows='3' style='width:100%'>" . htmlspecialchars($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>Gerar termo</td><td colspan='3'>";
        $generate_term_checked = !empty($this->fields['generate_term']) ? 'checked' : '';
        echo "<label style='cursor:pointer;display:flex;align-items:center;gap:8px'>
                <input type='checkbox' name='generate_term' value='1' {$generate_term_checked}>
                Exibir botão de gerar termo neste quadro
              </label>";
        echo "</td></tr>";

        if (!$is_new) {
            echo "<tr class='tab_bg_1'><td colspan='4' style='text-align:center;padding:12px'>";
            $kanban_url = Plugin::getWebDir('kanpro') . "/front/kanban.php?boards_id={$ID}";
            echo "<a href='{$kanban_url}' class='btn btn-primary' style='padding:10px 24px;font-size:14px'><i class='ti ti-layout-kanban'></i> Abrir Quadro Kanban</a> ";
            echo "<small style='margin-left:12px;color:#6b778c'>ID #{$ID} • Criado em " . Html::convDateTime($this->fields['date_creation'] ?? '') . "</small>";
            echo "</td></tr>";
        }

        $this->showFormButtons($options);
        return true;
    }

    // Lista de quadros para front/board.php
    static function getBoardsForEntity($entities_id = null, $include_archived = false) {
        global $DB;
        $entities = $entities_id ?? ($_SESSION['glpiactiveentities'] ?? [0]);
        if (!is_array($entities)) $entities = [$entities];
        $where = ['entities_id' => $entities];
        if (!$include_archived) $where['is_archived'] = 0;
        return $DB->request([
            'FROM'  => 'glpi_plugin_kanpro_boards',
            'WHERE' => $where,
            'ORDER' => 'is_starred DESC, date_mod DESC',
        ]);
    }

    static function countCardsInBoard($boards_id): int {
        global $DB;
        return countElementsInTable('glpi_plugin_kanpro_cards', [
            'plugin_kanpro_boards_id' => $boards_id,
            'is_archived' => 0,
        ]);
    }
}
