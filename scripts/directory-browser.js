document.addEventListener('DOMContentLoaded', function() {
    // Funkce pro tlačítko "Procházet"
    document.getElementById('browseButton').addEventListener('click', function() {
        // Vytvoříme skrytý input pro výběr adresáře
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.setAttribute('webkitdirectory', ''); // Pro Chrome
        fileInput.setAttribute('directory', ''); // Pro Firefox
        fileInput.setAttribute('mozdirectory', ''); // Pro starší Firefoxy
        
        fileInput.onchange = function() {
            if (this.files && this.files.length > 0) {
                // Získáme cestu adresáře z prvního souboru
                const filepath = this.files[0].webkitRelativePath || this.files[0].name;
                let folderPath = '';
                
                if (filepath.includes('/')) {
                    // Získáme kořenový adresář z relativní cesty
                    folderPath = filepath.split('/')[0];
                } else {
                    // Pokud máme jen název souboru
                    folderPath = filepath;
                }
                
                // Zkusíme odvodit absolutní cestu
                let currentPath = document.getElementById('mediaPath').value || '';
                if (currentPath) {
                    // Pokud už máme cestu, zkusíme ji aktualizovat
                    const lastSlashIndex = Math.max(
                        currentPath.lastIndexOf('/'), 
                        currentPath.lastIndexOf('\\')
                    );
                    if (lastSlashIndex >= 0) {
                        currentPath = currentPath.substring(0, lastSlashIndex);
                    }
                    folderPath = currentPath + '/' + folderPath;
                }
                
                // Aktualizujeme pole pro cestu
                document.getElementById('mediaPath').value = folderPath;
            }
        };
        
        // Simulujeme klik na input
        fileInput.click();
    });
    
    // Stejný kód pro tlačítko procházet na stránce editace
    const editBrowseButton = document.getElementById('editBrowseButton');
    if (editBrowseButton) {
        editBrowseButton.addEventListener('click', function() {
            // Stejný kód jako výše
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.setAttribute('webkitdirectory', '');
            fileInput.setAttribute('directory', '');
            fileInput.setAttribute('mozdirectory', '');
            
            fileInput.onchange = function() {
                if (this.files && this.files.length > 0) {
                    const filepath = this.files[0].webkitRelativePath || this.files[0].name;
                    let folderPath = '';
                    
                    if (filepath.includes('/')) {
                        folderPath = filepath.split('/')[0];
                    } else {
                        folderPath = filepath;
                    }
                    
                    let currentPath = document.getElementById('path').value || '';
                    if (currentPath) {
                        const lastSlashIndex = Math.max(
                            currentPath.lastIndexOf('/'), 
                            currentPath.lastIndexOf('\\')
                        );
                        if (lastSlashIndex >= 0) {
                            currentPath = currentPath.substring(0, lastSlashIndex);
                        }
                        folderPath = currentPath + '/' + folderPath;
                    }
                    
                    document.getElementById('path').value = folderPath;
                }
            };
            
            fileInput.click();
        });
    }
});