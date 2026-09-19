using System.Diagnostics;
using System.IO;
using System.Reflection;
using System.Text;

// 仅用于测试：CreateProcess 不经过 shell 时不会选择 .cmd 哨兵。
internal static class PhpCommand
{
    // 遵循 Windows C 运行库转义规则，shell 元字符保持为普通参数。
    private static string Quote(string value)
    {
        var result = new StringBuilder("\"");
        int slashes = 0;
        foreach (char character in value)
        {
            if (character == '\\') { slashes++; continue; }
            result.Append('\\', character == '"' ? slashes * 2 + 1 : slashes);
            result.Append(character);
            slashes = 0;
        }
        result.Append('\\', slashes * 2);
        return result.Append('"').ToString();
    }

    private static int Main(string[] arguments)
    {
        string executable = Assembly.GetExecutingAssembly().Location;
        var command = new StringBuilder(Quote(Path.ChangeExtension(executable, ".php")));
        foreach (string argument in arguments) { command.Append(' ').Append(Quote(argument)); }
        var start = new ProcessStartInfo(File.ReadAllText(Path.ChangeExtension(executable, ".php-binary")), command.ToString());
        start.UseShellExecute = false;
        using (var process = Process.Start(start))
        {
            process.WaitForExit();
            return process.ExitCode;
        }
    }
}
