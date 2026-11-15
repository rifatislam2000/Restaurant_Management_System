document.addEventListener('DOMContentLoaded', function () {
    const loginCSS = `
        :root {
            --bg-1: #f8fbff;
            --bg-2: #e0f2fe;
            --card: rgba(255,255,255,0.96);
            --primary: #0ea5e9;
            --primary-dark: #0284c7;
            --text: #1f2937;
            --muted: #64748b;
            --light-border: #dbeafe;
            --shadow: rgba(15, 23, 42, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg-1), var(--bg-2));
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
        }

        .form-body {
            width: min(100%, 460px);
            background: var(--card);
            border: 1px solid rgba(14, 165, 233, 0.15);
            border-radius: 24px;
            padding: 2.3rem 2rem;
            box-shadow: 0 18px 45px var(--shadow);
            backdrop-filter: blur(10px);
        }

        form.row.g-3 {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin: 0;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.45rem;
        }

        .form-control {
            width: 100%;
            height: 52px;
            border-radius: 12px !important;
            border: 1px solid var(--light-border) !important;
            background: #f8fbff;
            color: var(--text);
            padding: 0.85rem 1rem;
            font-size: 0.98rem;
            transition: all 0.25s ease;
        }

        .form-control:focus {
            border-color: var(--primary) !important;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.12);
            outline: none;
        }

        #show_hide_password {
            display: flex;
            align-items: center;
        }

        #show_hide_password .form-control {
            border-right: 0 !important;
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }

        #show_hide_password .input-group-text {
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            border: 1px solid var(--light-border);
            border-left: 0;
            border-radius: 0 12px 12px 0;
            background: #ffffff;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.25s ease;
        }

        #show_hide_password .input-group-text:hover {
            color: var(--primary);
        }

        #show_hide_password i {
            font-size: 1.1rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.95rem;
            color: var(--muted);
        }

        .form-check-input {
            width: 1.05rem;
            height: 1.05rem;
            accent-color: var(--primary);
        }

        a {
            color: var(--primary);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .btn-primary {
            width: 100%;
            height: 52px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            font-weight: 600;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 22px rgba(14, 165, 233, 0.24);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(14, 165, 233, 0.30);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .text-start p,
        .text-start {
            color: var(--muted);
            font-size: 0.96rem;
        }

        @media (max-width: 480px) {
            .form-body {
                width: calc(100% - 24px);
                padding: 1.6rem 1rem;
                border-radius: 18px;
            }

            .form-control,
            #show_hide_password .input-group-text,
            .btn-primary {
                height: 48px;
            }
        }
    `;

    const styleTag = document.createElement('style');
    styleTag.id = 'login-page-style';
    styleTag.textContent = loginCSS;
    document.head.appendChild(styleTag);

    const passwordInput = document.getElementById('inputChoosePassword');
    const toggleEl = document.querySelector('#show_hide_password .input-group-text');

    if (passwordInput && toggleEl) {
        toggleEl.addEventListener('click', function (e) {
            e.preventDefault();

            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';

            const icon = toggleEl.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye-fill', isPassword);
                icon.classList.toggle('bi-eye-slash-fill', !isPassword);
            }
        });
    }
});