# Integration AI Power 🚀

Sistema completo de integração entre Inteligência Artificial (LLMs via **Groq/Ollama**) e Banco de Dados Relacional, composto por uma **API de Alta Performance** (Hyperf) e um **Aplicativo Frontend** (Flutter).

Este projeto é voltado para dois perfis principais:
1.  **Desenvolvedores:** Que precisam acelerar a criação de tabelas, migrations e CRUDs.
2.  **Usuários/Analistas:** Que desejam conversar com seus dados e extrair insights sem escrever SQL.

---

## 🏛️ Arquitetura do Sistema

O sistema opera em uma arquitetura de microsserviços local:

*   **Backend (API):** Desenvolvido em **PHP (Hyperf/Swoole)**, atua como o cérebro que traduz linguagem natural para comandos de banco de dados e gerencia a conexão com o LLM.
*   **Frontend (App):** Desenvolvido em **Flutter** (Android/iOS/Web), provê uma interface amigável para interação com o agente.
*   **AI Engine:** Flexível: configurado nativamente para usar **Groq** (nuvem/alta performance) via driver compatível, mas suporta **Ollama** (local).
*   **Banco de Dados:** MySQL 8.0.

---

## 📋 Funcionalidades

### 🛠️ Para Desenvolvedores (Aceleradores)

*   **Criação de Tabelas via IA:** Descreva a tabela em português (ex: "Crie uma tabela de usuários com login, senha e data de cadastro") e o sistema gera e executa o DDL.
*   **Geração Automática de CRUD:** A partir de uma tabela existente, gera automaticamente os arquivos de **Model** e **Controller** no backend.
*   **Migrações Inteligentes:** Evolua o esquema do banco apenas descrevendo a mudança (ex: "Adicione uma coluna telefone na tabela clientes").
*   **Chat com Contexto:** Utilize o chat livre para tirar dúvidas técnicas ou pedir sugestões de arquitetura.

### 📊 Para Usuários e Analistas (Agente de Dados)

*   **Text-to-SQL (Consultas):** Pergunte ao banco de dados em linguagem natural (ex: "Quais os 5 produtos mais vendidos hoje?").
*   **Análise & Insights:** O sistema não apenas traz os dados, mas gera insights textuais sobre o resultado (ex: "O produto X representa 40% das vendas...").
*   **Modo Comando:** Insira, atualize ou remova dados conversando com o agente (ex: "Cadastre um novo cliente chamado João").

---

## 🚀 Como Rodar o Projeto

### Pré-requisitos
1.  **Docker** e **Docker Compose** instalados.
2.  **Flutter SDK** instalado (para rodar o frontend).
3.  **Ollama** instalado localmente.
    *   Execute: `ollama pull llama3` (ou o modelo de sua preferência configurado no `.env`).

### 1. Inicializando o Backend (API)

O backend roda totalmente em Docker.

1.  Clone este repositório.
2.  Navegue até a raiz do projeto e suba os containers:
    ```bash
    docker compose up -d
    ```
3.  A API estará disponível em `http://localhost:9600`.

**Comandos Úteis (Backend):**
*   Entrar no container: `docker exec -it integration-app /bin/bash`
*   Ver logs: `docker compose logs -f app`
*   Reiniciar app (necessário após gerar CRUD): `docker compose restart app`

### 2. Inicializando o Frontend (App)

O frontend está na pasta `frontend/`.

1.  Navegue até a pasta:
    ```bash
    cd frontend
    ```
2.  Instale as dependências:
    ```bash
    flutter pub get
    ```
3.  Execute o app (Web, Android ou Windows):
    ```bash
    flutter run
    ```

---

## 📚 Documentação da API (Backend)

Aqui estão os principais endpoints para integração direta.

### 🏗️ Manipulação de Estrutura (DDL)

#### Criar Tabela
`POST /ai/create-table`
```json
{ "description": "Crie tabela de pedidos com id, total e cliente_id" }
```

#### Migração (Alterar Tabela)
`POST /ai/migrate`
```json
{ "table": "pedidos", "command": "Adicione status como varchar" }
```

#### Gerar Código (CRUD)
`POST /ai/generate-crud`
```json
{ "table": "pedidos" }
```
*Gera arquivos em `app/Model` e `app/Controller`.*

### 🔍 Manipulação de Dados (DML) & Consultas

#### Consultar Dados (Text-to-SQL)
`POST /ai/query`
```json
{ "question": "Quantos pedidos foram feitos hoje?" }
```

#### Executar Comandos (Insert/Update/Delete)
`POST /ai/command`
```json
{ "command": "Adicione um pedido de valor 150.00 para o cliente 1" }
```

#### Analisar Dados (Query + Insight)
`POST /ai/analyze-query`
```json
{ "question": "Qual a tendência de vendas?" }
```
*Retorna os dados brutos E um texto explicativo gerado pela IA.*

### 💬 Chat Livre
`POST /ai/chat-free`
```json
{ "message": "Como posso otimizar uma tabela MySQL?" }
```
*(Também disponível via Stream em `/ai/chat-free-stream`)*

---

## 🧪 Como Testar

### Backend (PHPUnit)
Para rodar os testes automatizados do backend (localizados em `test/`):

1.  Entre no container:
    ```bash
    docker exec -it integration-app /bin/bash
    ```
2.  Execute o Composer Test:
    ```bash
    composer test
    ```
    *Ou manualmente:* `php vendor/bin/phpunit`

### Frontend (Flutter Test)
Para testar a interface e lógica do app:

```bash
cd frontend
flutter test
```

---

## 📄 Estrutura de Diretórios Importantes

```
.
├── app/
│   ├── Controller/      # Controladores da API (AIController.php)
│   ├── Service/         # Lógica de Negócio (OllamaService, DatabaseManager)
│   └── Model/           # Modelos do Banco
├── frontend/            # Código Fonte do App Flutter
│   └── lib/
│       ├── screens/     # Telas do App (Dashboard, Explore)
│       └── main.dart    # Ponto de entrada
├── test/                # Testes Unitários/Integração PHP
├── docker-compose.yml   # Orquestração dos containers
└── seed_products.sql    # Dados iniciais de exemplo
```

## 📄 Licença

MIT
