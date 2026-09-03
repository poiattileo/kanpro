# KanPro — Trello-like Kanban for GLPI 11

> Quadros Kanban completos dentro do GLPI. Experiência idêntica ao Trello: arraste, organize e colabore — direto no menu **Ferramentas**.

[![GLPI](https://img.shields.io/badge/GLPI-11.0%2B-0079bf?style=flat-square&logo=php)](https://glpi-project.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0-61bd4f?style=flat-square)](setup.php)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen?style=flat-square)](https://github.com/poiattileo/kanpro/pulls)

<p align="center">
  <img src="https://via.placeholder.com/960x480/0079bf/ffffff?text=KanPro+%7C+Trello+for+GLPI" alt="KanPro preview" width="100%">
  <br>
  <em>Arraste cartões entre listas, gerencie membros, etiquetas, checklists e datas — sem sair do GLPI.</em>
</p>

---

## ✨ Por que KanPro?

| Trello | KanPro no GLPI |
|---|---|
| Quadros, listas e cartões | ✅ 100% paridade |
| Drag & drop | ✅ HTML5 puro, sem dependências |
| Etiquetas, membros, capas | ✅ + filtros combinados |
| Checklists & progresso | ✅ múltiplas por cartão |
| Datas & vencimentos | ✅ badges (atrasado / vence em breve / concluído) |
| Comentários & atividade | ✅ timeline por cartão e por quadro |
| Anexos | ✅ upload direto no cartão |
| Calendário | ✅ visão mensal por data de entrega |

## 🎯 Funcionalidades

**Quadros**
- Cores de fundo (11 predefinidas + personalizada) · Visibilidade Privado/Equipe/Público · Favoritar ★ · Arquivar

**Listas**
- Criar, renomear (clique no título), reordenar por arraste · Copiar (com cartões) · Arquivar · Excluir

**Cartões**
- Título, descrição, capa colorida · Mover / Copiar / Arquivar · Drag & drop entre listas

**Organização**
- **Etiquetas** coloridas (6 padrão + ilimitadas) · Atribuir com 1 clique · Filtro por etiqueta
- **Membros** do quadro (admin/member/observer) · Atribuir no cartão · Avatares · Filtro por membro
- **Datas** início/entrega + concluído · Badges automáticos
- **Checklists** múltiplas, itens com checkbox, progresso `%`
- **Anexos** (20 MB) · **Comentários** · **Atividade** em tempo real

**Produtividade**
- Busca instantânea por nome/descrição · Filtro combinado (texto + etiqueta + membro) · Calendário mensal · Atalhos (Enter para adicionar, Esc fecha modais)

---

## 📦 Instalação

### 1. Baixar
```bash
cd /caminho/do/glpi/plugins
git clone https://github.com/poiattileo/kanpro.git kanpro
# ou baixe o ZIP e extraia como "kanpro"
```

> A pasta **deve** se chamar `kanpro` (minúsculas).

### 2. Ativar
GLPI → **Configurar → Plugins** → *KanPro - Projetos Kanban* → **Instalar** → **Ativar**

### 3. Permissões
**Administração → Perfis → seu perfil → aba KanPro**

| Nível | Acesso |
|---|---|
| Sem acesso | — |
| Visualizar | Ver quadros |
| Criar | Ver + criar quadros/cartões |
| Editar | Ver, criar e editar |
| Total | Ver, criar, editar, apagar e expurgar |

Super-admin recebe acesso total automaticamente.

### 4. Usar
**Ferramentas → Quadros Kanban → Criar quadro**

---

## 🚀 Guia rápido

1. **Criar quadro** → nome + cor
2. **Abrir quadro** → clique em *Abrir*
3. **Listas** → *Adicionar outra lista* no fim · clique no título para renomear · `⋯` para ações
4. **Cartões** → *Adicionar um cartão* na lista · arraste entre listas · clique para abrir
5. **Modal do cartão** → edite tudo na mesma tela: membros, etiquetas, checklist, datas, capa, anexos, comentários. Ações à direita: Mover · Copiar · Arquivar
6. **Filtrar** → campo no topo ou botão *Filtrar* (etiqueta/membro) · **Calendário** → visão mensal por vencimento

---

## 🗃️ Banco de dados

Criadas automaticamente na instalação (`hook.php`):

`glpi_plugin_kanpro_boards` · `lists` · `cards` · `labels` · `cards_labels` · `cards_members` · `boards_members` · `checklists` · `checklist_items` · `comments` · `attachments` · `activities`

A desinstalação remove todas as tabelas e arquivos em `files/_plugins/kanpro/`.

---

## 📂 Estrutura

```
kanpro/
├── setup.php              # definição do plugin e menu Ferramentas
├── hook.php               # install / uninstall
├── inc/                   # classes GLPI (CommonDBTM)
│   ├── board.class.php
│   ├── list.class.php
│   ├── card.class.php
│   ├── label.class.php
│   ├── checklist.class.php
│   ├── comment.class.php
│   ├── attachment.class.php
│   ├── activity.class.php
│   └── profile.class.php
├── front/                 # páginas e API
│   ├── board.php
│   ├── board.form.php
│   ├── kanban.php
│   ├── ajax.php
│   └── attachment.php
├── public/
│   ├── css/kanpro.css     # design system Trello
│   └── js/kanpro.js       # drag & drop vanilla JS
└── locales/pt_BR.php
```

---

## ⚙️ Compatibilidade

- **GLPI** 11.0.0+
- **PHP** 8.1+ (8.2/8.3 recomendado)
- **MySQL** 8.0+ / **MariaDB** 10.6+

Sem dependências externas. Usa **Tabler Icons** (`ti`) já incluídos no GLPI 11 e **Vanilla JS** (sem jQuery).

---

## 🛠️ Desenvolvimento

```bash
# lint
php -l setup.php
php -l hook.php
php -l inc/*.php
php -l front/*.php

# checar JS
node --check public/js/kanpro.js
```

---

## 🤝 Contribuindo

Contribuições são bem-vindas!

1. Fork o repositório
2. Crie uma branch: `git checkout -b feat/minha-feature`
3. Commit: `git commit -m "feat: minha feature"`
4. Push e abra um Pull Request

Reporte bugs em [Issues](https://github.com/poiattileo/kanpro/issues).

---

## 📝 Licença

[GPLv3](LICENSE) — mesmo licenciamento do GLPI.

---

<p align="center">
  Feito para a comunidade GLPI. Se o KanPro te ajudou, deixe uma ⭐ no repositório!
</p>
