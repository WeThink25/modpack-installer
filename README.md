# WeThink Modpack Installer

One click modpack installer from Modrinth.

## 🚀 Features

- **One-Click Installation**: Choose a modpack and let the installer handle everything.
- **Smart Build Selection**: Choose between **Stable (Release)**, **Beta**, and **Alpha** builds.
- **Smart Config Migration**: Preserves your essential server files (`server.properties`, `whitelist.json`, etc.) during updates.
- **Complete Cleanup**: Uninstaller removes all mods and tracking metadata accurately using the Modrinth index.
- **Responsive UI**: Fully optimized for mobile, tablet, and desktop screens.
- **Zero-Config Search**: Automatically filters modpacks based on your server's current Loader (Fabric/Forge) and Minecraft version.

## 🛠 Installation

1. Upload the `wethink-modpack-installer` folder to your Pelican `plugins` directory.
2. Ensure your server's Egg has the `wethink_modpack` tag or feature enabled.
3. The "Modpack Installer" menu will appear in your server sidebar.

## ⚙️ How it Works

1. **Search**: The plugin queries the Modrinth API for modpacks compatible with your server's loader.
2. **Install**:
   - Captures existing configurations.
   - Downloads the `.mrpack` file.
   - Decompresses it via the Daemon API.
   - Parses `modrinth.index.json` to download all constituent mods automatically.
3. **Uninstall**:
   - Reads the recorded index and deletes only the files associated with the modpack.
   - Clears the metadata cache for an instant UI update.

## 📝 Note

The plugin was created with AI assistance. This resource is free to use.
