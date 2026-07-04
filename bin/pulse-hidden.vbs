' XAMPP Pulse - hidden launcher.
' wscript.exe is a windowless (GUI-subsystem) host, so running this script shows no console.
' It relaunches the target PowerShell wrapper fully hidden (Run window style 0), which starts
' PowerShell with its console already hidden - so the pulsessh:// / pulsefolder:// handlers
' open a terminal / Explorer without a console window flashing on screen.
' Arg 0 = path to the .ps1 wrapper; args 1.. = arguments passed straight through to it.
Option Explicit
Dim args, cmd, i
Set args = WScript.Arguments
If args.Count < 1 Then WScript.Quit 1
cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & args(0) & """"
For i = 1 To args.Count - 1
    cmd = cmd & " """ & args(i) & """"
Next
CreateObject("WScript.Shell").Run cmd, 0, False
