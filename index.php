
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCT | Sistema de Chamados Técnico</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #0c1933, #183d65);
            color: white;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 480px;
            padding: 45px 30px;
            background: #ffffff12;
            border: 1px solid #ffffff30;
            border-radius: 18px;
            text-align: center;
            box-shadow: 0 15px 40px #00000035;
        }

        .logo {
            font-size: 48px;
            font-weight: bold;
            color: #61dafb;
            margin-bottom: 12px;
        }

        h1 { font-size: 25px; }

        p {
            color: #d6e0ee;
            line-height: 1.7;
        }

        .botao {
            display: block;
            background: #25d366;
            color: #07351b;
            padding: 17px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 17px;
            font-weight: bold;
            margin-top: 28px;
            transition: 0.2s;
        }

        .botao:hover {
            background: #1fba58;
            transform: translateY(-2px);
        }

        footer {
            margin-top: 35px;
            color: #a9b9cd;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <main class="container">
        <div class="logo">SCT</div>

        <h1>Sistema de Chamados Técnico</h1>

        <p>
            Precisa de suporte técnico?
            Abra um chamado de maneira simples
            e rápida pelo WhatsApp.
        </p>

        <a class="botao"
           href="https://wa.me/5521991579813?text=Ol%C3%A1%21%20Quero%20abrir%20um%20chamado%20t%C3%A9cnico."
           target="_blank"
           rel="noopener noreferrer">
            Abrir chamado pelo WhatsApp
        </a>

        <footer>
            SCT - Sistema de Chamados Técnico
        </footer>
    </main>
</body>
</html>
