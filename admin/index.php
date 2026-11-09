
<?php
$url_tujuan = "dashboard.php";
$waktu_redirect = 3;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta http-equiv="refresh"
          content="<?= $waktu_redirect ?>;url=<?= htmlspecialchars($url_tujuan, ENT_QUOTES, 'UTF-8') ?>">

    <title>Mengalihkan Halaman</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #eef2ff, #f8fafc);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            padding: 40px 30px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.08);
        }

        .icon {
            width: 65px;
            height: 65px;
            background: #e8f0ff;
            color: #2563eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 30px;
        }

        h2 {
            color: #1e293b;
            font-size: 22px;
            margin-bottom: 12px;
        }

        p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
        }

        .loader {
            width: 45px;
            height: 45px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #2563eb;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 25px auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .countdown {
            color: #2563eb;
            font-weight: bold;
        }

        .btn {
            display: inline-block;
            margin-top: 25px;
            padding: 12px 25px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            transition: 0.3s;
        }

        .btn:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
        }

        .footer {
            margin-top: 25px;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>

<body>

    <div class="container">

        <div class="icon">
            ✓
        </div>

        <h2>Berhasil!</h2>

        <p>
            Anda berhasil masuk ke sistem.
            Mohon tunggu, halaman sedang dialihkan.
        </p>

        <div class="loader"></div>

        <p>
            Mengalihkan dalam
            <span class="countdown" id="countdown">
                <?= $waktu_redirect ?>
            </span>
            detik...
        </p>

        <a href="<?= htmlspecialchars($url_tujuan, ENT_QUOTES, 'UTF-8') ?>"
           class="btn">
            Lanjutkan Sekarang
        </a>

        <div class="footer">
            &copy; <?= date('Y') ?> Sistem Informasi
        </div>

    </div>

    <script>
        let waktu = <?= (int) $waktu_redirect ?>;

        const countdown = document.getElementById("countdown");

        const timer = setInterval(function () {
            waktu--;

            countdown.textContent = waktu;

            if (waktu <= 0) {
                clearInterval(timer);
                window.location.href = <?= json_encode($url_tujuan) ?>;
            }
        }, 1000);
    </script>

</body>
</html>
