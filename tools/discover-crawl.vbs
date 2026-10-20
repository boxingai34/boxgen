' Satu crawl DISCOVER tanpa jendela konsol yang berkedip.
'
' Dipanggil Task Scheduler tiap 30 menit:
'   schtasks /Create /TN "BoxGen DISCOVER" /SC MINUTE /MO 30 /F ^
'     /TR "wscript.exe \"C:\xampp2\htdocs\boxgen\tools\discover-crawl.vbs\""
'
' MySQL harus menyala (XAMPP Control) — kalau tidak, crawl gagal diam-diam
' dan dicoba lagi 30 menit kemudian.

Dim fso, sh, akar
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
akar = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName))

sh.Run """C:\xampp2\php\php.exe"" """ & akar & "\v2\artisan"" discover:crawl", 0, False
