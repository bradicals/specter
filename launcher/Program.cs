using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Runtime.InteropServices;
using System.Windows.Forms;
using Microsoft.Web.WebView2.WinForms;

#pragma warning disable CA1416

static class Program
{
    [STAThread]
    static void Main()
    {
        ApplicationConfiguration.Initialize();
        Application.Run(new SpecterForm());
    }
}

class SpecterForm : Form
{
    // ── P/Invoke ──────────────────────────────────────────────────────────────
    const int WM_NCLBUTTONDOWN = 0x00A1;
    const int WM_HOTKEY        = 0x0312;
    const int HTCAPTION        = 2;
    const int HTLEFT           = 10;
    const int HTRIGHT          = 11;
    const int HTTOP            = 12;
    const int HTTOPLEFT        = 13;
    const int HTTOPRIGHT       = 14;
    const int HTBOTTOM         = 15;
    const int HTBOTTOMLEFT     = 16;
    const int HTBOTTOMRIGHT    = 17;
    const int MOD_CONTROL      = 0x0002;
    const int MOD_SHIFT        = 0x0004;
    const int HOTKEY_ID        = 1;

    [DllImport("user32.dll")] static extern bool   ReleaseCapture();
    [DllImport("user32.dll")] static extern IntPtr SendMessage(IntPtr hWnd, int msg, IntPtr wParam, IntPtr lParam);
    [DllImport("user32.dll")] static extern bool   RegisterHotKey(IntPtr hWnd, int id, int fsModifiers, int vk);
    [DllImport("user32.dll")] static extern bool   UnregisterHotKey(IntPtr hWnd, int id);
    [DllImport("user32.dll")] static extern bool   SetWindowPos(IntPtr hWnd, IntPtr hWndInsertAfter, int X, int Y, int cx, int cy, uint uFlags);
    [DllImport("user32.dll")] static extern int    SetWindowRgn(IntPtr hWnd, IntPtr hRgn, bool bRedraw);
    [DllImport("gdi32.dll")]  static extern IntPtr CreateRoundRectRgn(int x1, int y1, int x2, int y2, int cx, int cy);
    [DllImport("gdi32.dll")]  static extern IntPtr CreateRectRgn(int x1, int y1, int x2, int y2);
    [DllImport("gdi32.dll")]  static extern int    CombineRgn(IntPtr dest, IntPtr src1, IntPtr src2, int mode);
    [DllImport("gdi32.dll")]  static extern bool   DeleteObject(IntPtr hObject);

    const int RGN_OR = 2;
    [DllImport("dwmapi.dll")] static extern int    DwmExtendFrameIntoClientArea(IntPtr hwnd, ref MARGINS margins);

    static readonly IntPtr HWND_TOPMOST = new(-1);
    const uint SWP_NOMOVE = 0x0002;
    const uint SWP_NOSIZE = 0x0001;

    [System.Runtime.InteropServices.StructLayout(System.Runtime.InteropServices.LayoutKind.Sequential)]
    struct MARGINS { public int Left, Right, Top, Bottom; }

    // ── Fields ────────────────────────────────────────────────────────────────
    private readonly WebView2    _wv      = new();
    private readonly string      _cfgPath;
    private          Process?    _php;
    private          NotifyIcon  _tray      = null!;
    private          System.Windows.Forms.Timer _hoverTimer = null!;
    private          Point       _savedPos;
    private          Size        _savedSize;
    private          bool        _stashed    = false;
    private          bool        _tabVisible = false;

    const int TAB_WIDTH  = 46;
    const int TAB_HEIGHT = 200;

    public SpecterForm()
    {
        _cfgPath = Path.Combine(AppContext.BaseDirectory, "specter.cfg");

        FormBorderStyle   = FormBorderStyle.None;
        ShowInTaskbar     = true;
        TopMost           = false;
        Width             = 980;
        Height            = 600;
        BackColor         = Color.Black;
        AllowTransparency = true;
        Text              = "Specter";

        RestorePosition();

        _wv.Dock = DockStyle.Fill;
        Controls.Add(_wv);

        SetupTray();
        SetupHoverTimer();

        Load        += OnLoad;
        FormClosing += OnClosing;
    }

    // ── Tray icon ─────────────────────────────────────────────────────────────
    private void SetupTray()
    {
        _tray = new NotifyIcon
        {
            Icon    = CreateTrayIcon(),
            Text    = "Specter — click to show",
            Visible = false,
        };
        _tray.Click += (s, e) =>
        {
            UnstashFromEdge();
            _ = _wv.CoreWebView2?.ExecuteScriptAsync("unstashApp()");
        };
    }

    private static Icon CreateTrayIcon()
    {
        var bmp = new Bitmap(32, 32);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.AntiAlias;
            g.Clear(Color.FromArgb(14, 16, 24));
            using var brush = new SolidBrush(Color.FromArgb(123, 143, 255));
            using var font  = new Font("Segoe UI", 18f, FontStyle.Bold, GraphicsUnit.Pixel);
            var sf = new StringFormat { Alignment = StringAlignment.Center, LineAlignment = StringAlignment.Center };
            g.DrawString("S", font, brush, new RectangleF(0, 0, 32, 32), sf);
        }
        return Icon.FromHandle(bmp.GetHicon());
    }

    // ── Hotkey ────────────────────────────────────────────────────────────────
    protected override void OnHandleCreated(EventArgs e)
    {
        base.OnHandleCreated(e);
        RegisterHotKey(Handle, HOTKEY_ID, MOD_CONTROL | MOD_SHIFT, (int)Keys.S);
        // Extend DWM frame across entire client area — gives true per-pixel
        // transparency without TransparencyKey's click-through side effect
        var m = new MARGINS { Left = -1, Right = -1, Top = -1, Bottom = -1 };
        DwmExtendFrameIntoClientArea(Handle, ref m);
    }

    protected override void OnHandleDestroyed(EventArgs e)
    {
        UnregisterHotKey(Handle, HOTKEY_ID);
        base.OnHandleDestroyed(e);
    }

    protected override void WndProc(ref Message m)
    {
        if (m.Msg == WM_HOTKEY && m.WParam.ToInt32() == HOTKEY_ID)
        {
            if (_stashed)
            {
                UnstashFromEdge();
                _ = _wv.CoreWebView2?.ExecuteScriptAsync("unstashApp()");
            }
            else
            {
                StashToEdge();
                _ = _wv.CoreWebView2?.ExecuteScriptAsync("stashApp()");
            }
        }
        base.WndProc(ref m);
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    private async void OnLoad(object? sender, EventArgs e)
    {
        StartPhpServer();

        await _wv.EnsureCoreWebView2Async();
        _wv.DefaultBackgroundColor = Color.Transparent;
        _wv.CoreWebView2.WebMessageReceived += OnWebMessage;
        _wv.CoreWebView2.NewWindowRequested += OnNewWindow;
        _wv.Source = new Uri("http://localhost:3333");
    }

    private void StartPhpServer()
    {
        var root      = Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "..", "..", "..", ".."));
        var serverPhp = Path.Combine(root, "server.php");

        _php = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName         = "php",
                Arguments        = $"-S localhost:3333 \"{serverPhp}\"",
                WorkingDirectory = root,
                UseShellExecute  = false,
                CreateNoWindow   = true,
            }
        };
        _php.Start();
    }

    private void OnClosing(object? sender, FormClosingEventArgs e)
    {
        _tray.Visible = false;
        _tray.Dispose();

        if (_stashed)
            try { File.WriteAllText(_cfgPath, $"{_savedPos.X},{_savedPos.Y},{_savedSize.Width},{_savedSize.Height}"); }
            catch { /* ignore */ }
        else
            SavePosition();

        try { _php?.Kill(entireProcessTree: true); } catch { /* ignore */ }
    }

    // ── Messages from JS ─────────────────────────────────────────────────────
    private void OnWebMessage(object? sender,
        Microsoft.Web.WebView2.Core.CoreWebView2WebMessageReceivedEventArgs e)
    {
        var msg = e.TryGetWebMessageAsString();
        switch (msg)
        {
            case "close":      Invoke(Application.Exit);             break;
            case "drag":       Invoke(BeginDrag);                    break;
            case "stash":      Invoke(StashToEdge);                  break;
            case "unstash":    Invoke(UnstashFromEdge);              break;
            case "resize-t":   Invoke(() => BeginResize(HTTOP));         break;
            case "resize-b":   Invoke(() => BeginResize(HTBOTTOM));      break;
            case "resize-l":   Invoke(() => BeginResize(HTLEFT));        break;
            case "resize-r":   Invoke(() => BeginResize(HTRIGHT));       break;
            case "resize-tl":  Invoke(() => BeginResize(HTTOPLEFT));     break;
            case "resize-tr":  Invoke(() => BeginResize(HTTOPRIGHT));    break;
            case "resize-bl":  Invoke(() => BeginResize(HTBOTTOMLEFT));  break;
            case "resize-br":  Invoke(() => BeginResize(HTBOTTOMRIGHT)); break;
        }
    }

    private void OnNewWindow(object? sender,
        Microsoft.Web.WebView2.Core.CoreWebView2NewWindowRequestedEventArgs e)
    {
        e.Handled = true;
        Process.Start(new ProcessStartInfo(e.Uri) { UseShellExecute = true });
    }

    private void BeginDrag()
    {
        ReleaseCapture();
        SendMessage(Handle, WM_NCLBUTTONDOWN, (IntPtr)HTCAPTION, IntPtr.Zero);
    }

    private void BeginResize(int htCode)
    {
        ReleaseCapture();
        SendMessage(Handle, WM_NCLBUTTONDOWN, (IntPtr)htCode, IntPtr.Zero);
    }

    // ── Hover timer (shows/hides stash tab on cursor proximity) ──────────────
    private void SetupHoverTimer()
    {
        _hoverTimer = new System.Windows.Forms.Timer { Interval = 80 };
        _hoverTimer.Tick += (s, e) =>
        {
            var cursor = Cursor.Position;
            bool over  = Bounds.Contains(cursor);
            if (over && !_tabVisible)
            {
                // Force to top of z-order over every other window, then show
                SetWindowPos(Handle, HWND_TOPMOST, 0, 0, 0, 0, SWP_NOMOVE | SWP_NOSIZE);
                Opacity      = 1.0;
                _tabVisible  = true;
            }
            else if (!over && _tabVisible)
            {
                Opacity      = 0.0;
                _tabVisible  = false;
            }
        };
    }

    // ── Stash ────────────────────────────────────────────────────────────────
    private void StashToEdge()
    {
        if (_stashed) return;
        _savedPos  = Location;
        _savedSize = new Size(Width, Height);
        var bounds = Screen.FromControl(this).Bounds;
        int tabY   = bounds.Top + (bounds.Height - TAB_HEIGHT) / 2;
        Width    = TAB_WIDTH;
        Height   = TAB_HEIGHT;
        Location = new Point(bounds.Right - TAB_WIDTH, tabY);
        _stashed      = true;
        _tabVisible   = false;
        ShowInTaskbar = false;
        TopMost       = true;
        Opacity       = 0.0;
        _tray.Visible = true;

        // Clip window to folder-tab shape: rounded left corners, flat right edge
        int tabH      = 160;
        int tabTop    = (TAB_HEIGHT - tabH) / 2;
        int midX      = TAB_WIDTH / 2;
        // Start with fully-rounded rect, then OR a rectangle over the right half
        // to square off the right corners, leaving only left corners rounded
        var rgnRound  = CreateRoundRectRgn(0, tabTop, TAB_WIDTH, tabTop + tabH, 32, 32);
        var rgnSquare = CreateRectRgn(midX, tabTop, TAB_WIDTH, tabTop + tabH);
        var rgnFinal  = CreateRectRgn(0, 0, 1, 1);
        CombineRgn(rgnFinal, rgnRound, rgnSquare, RGN_OR);
        DeleteObject(rgnRound);
        DeleteObject(rgnSquare);
        SetWindowRgn(Handle, rgnFinal, true); // system owns rgnFinal — do not delete

        _hoverTimer.Start();
    }

    private void UnstashFromEdge()
    {
        if (!_stashed) return;
        _hoverTimer.Stop();
        _tabVisible   = false;
        Opacity       = 1.0;
        TopMost       = false;
        SetWindowRgn(Handle, IntPtr.Zero, true); // restore full window shape
        Width    = _savedSize.Width;
        Height   = _savedSize.Height;
        Location = _savedPos;
        _stashed      = false;
        ShowInTaskbar = true;
        _tray.Visible = false;
        Activate();
    }

    // ── Persistence ───────────────────────────────────────────────────────────
    private void SavePosition()
    {
        try { File.WriteAllText(_cfgPath, $"{Left},{Top},{Width},{Height}"); }
        catch { /* ignore */ }
    }

    private void RestorePosition()
    {
        try
        {
            if (!File.Exists(_cfgPath)) return;
            var p = File.ReadAllText(_cfgPath).Split(',');
            if (p.Length >= 2 &&
                int.TryParse(p[0], out int x) && int.TryParse(p[1], out int y))
            {
                StartPosition = FormStartPosition.Manual;
                Location = new Point(x, y);
            }
            if (p.Length >= 4 &&
                int.TryParse(p[2], out int w) && int.TryParse(p[3], out int h))
            {
                Width  = Math.Max(400, w);
                Height = Math.Max(300, h);
            }
        }
        catch { /* ignore */ }
    }
}
