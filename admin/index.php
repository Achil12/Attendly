<?php
$url_tujuan = "dashboard.php";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="3;url=<?php echo htmlspecialchars($url_tujuan, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Mengalihkan Halaman</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .container {
            background: white;
            padding: 35px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 3px 15px rgba(0,0,0,0.1);
        }

        .loader {
            border: 5px solid #eee;
            border-top: 5px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        a {
            color: #007bff;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Mengalihkan Halaman...</h2>
    <div class="loader"></div>
    <p>Mohon tunggu, Anda akan dialihkan dalam 3 detik.</p>
    <p>
        Jika tidak dialihkan,
        <a href="<?php echo htmlspecialchars($url_tujuan, ENT_QUOTES, 'UTF-8'); ?>">        
            klik di sini
        </a>.
    </p>
</div>

</body>
</html>
