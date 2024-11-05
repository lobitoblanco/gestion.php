<?php
// Habilitar la visualización de errores
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

// Conectar a la base de datos
$servername = "localhost";
$username = "root"; // Cambia esto si es necesario
$password = ""; // Cambia esto si es necesario
$dbname = "gestionhospitalaria";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar la conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Procesar formulario de añadir paciente
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["add_patient"])) {
    $nombre = $_POST["nombre"];
    $apellido = $_POST["apellido"];
    $fecha_nacimiento = $_POST["fecha_nacimiento"];
    $sexo = $_POST["sexo"];
    $numero_cama = $_POST["cama"] ?? ""; // Asignar una cama, si se selecciona
    $fecha_ingreso = date("Y-m-d");

    // Insertar paciente en la base de datos
    $sql_add_patient = "INSERT INTO pacientes (nombre, apellido, fecha_nacimiento, sexo, fecha_ingreso) 
                        VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql_add_patient);
    $stmt->bind_param(
        "sssss",
        $nombre,
        $apellido,
        $fecha_nacimiento,
        $sexo,
        $fecha_ingreso
    );

    if ($stmt->execute()) {
        $id_paciente = $stmt->insert_id; // Obtener el ID del paciente recién insertado

        // Asignar paciente a la cama
        if ($numero_cama) {
            // Verificar si la cama con el número dado existe
            $sql_check_cama = "SELECT * FROM camas WHERE numero_cama = ?";
            $stmt_check_cama = $conn->prepare($sql_check_cama);
            $stmt_check_cama->bind_param("s", $numero_cama);
            $stmt_check_cama->execute();
            $result_check_cama = $stmt_check_cama->get_result();

            if ($result_check_cama->num_rows > 0) {
                // La cama existe, obtener su ID
                $row = $result_check_cama->fetch_assoc();
                $id_cama = $row['id'];

                // Proceder a asignar la cama al paciente
                $sql_assign_bed = "UPDATE camas SET id_paciente = ?, estado = 'ocupado' WHERE id = ?";
                $stmt_assign = $conn->prepare($sql_assign_bed);
                $stmt_assign->bind_param("ii", $id_paciente, $id_cama);

                if ($stmt_assign->execute()) {
                    // Registrar cama
                    $sql_insert_registro = "INSERT INTO registro_camas (id_cama, id_paciente, fecha_ingreso, fecha_salida) VALUES (?, ?, ?, NULL)";
                    $stmt_registro = $conn->prepare($sql_insert_registro);
                    $stmt_registro->bind_param("iis", $id_cama, $id_paciente, $fecha_ingreso);

                    if ($stmt_registro->execute()) {
                        // Redireccionar a la misma página después de añadir
                        header("Location: " . $_SERVER["PHP_SELF"]);
                        exit();
                        echo "Nuevo paciente añadido y cama asignada exitosamente.";
                    } else {
                        echo "Error al registrar cama: " . $stmt_registro->error;
                    }
                } else {
                    echo "Error al asignar la cama: " . $stmt_assign->error;
                }
            } else {
                echo "Error: La cama con número " . $numero_cama . " no existe.";
            }
        } else {
            echo "Nuevo paciente añadido exitosamente.";
        }
    } else {
        echo "Error al añadir paciente: " . $stmt->error;
    }
}

// Procesar eliminación de paciente
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_patient"])) {
    $id_paciente = $_POST["delete_patient"];

    // Obtener la cama asignada antes de eliminar al paciente
    $sql_get_bed = "SELECT id FROM camas WHERE id_paciente = ?";
    $stmt_get_bed = $conn->prepare($sql_get_bed);
    $stmt_get_bed->bind_param("i", $id_paciente);
    $stmt_get_bed->execute();
    $result_get_bed = $stmt_get_bed->get_result();

    if ($result_get_bed->num_rows > 0) {
        // Hay una cama asignada, obtener el id_cama
        $row = $result_get_bed->fetch_assoc();
        $id_cama = $row['id']; // Obtener el ID de la cama

        // Actualizar el registro de la cama con la fecha de salida
        $sql_update_registro = "UPDATE registro_camas SET fecha_salida = NOW() WHERE id_paciente = ? AND id_cama = ?";
        $stmt_update_registro = $conn->prepare($sql_update_registro);
        $stmt_update_registro->bind_param("ii", $id_paciente, $id_cama);
        $stmt_update_registro->execute();
    }

    // Eliminar el paciente de la cama si está asignado
    $sql_unassign_bed = "UPDATE camas SET id_paciente = NULL, estado = 'libre' WHERE id_paciente = ?";
    $stmt_unassign = $conn->prepare($sql_unassign_bed);
    $stmt_unassign->bind_param("i", $id_paciente);
    $stmt_unassign->execute();

    // Redireccionar a la misma página después de eliminar
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
    echo "Paciente desasignado exitosamente.";
}



// Consultar datos directamente

$search_query = $_POST["search"] ?? "";
$sql = "SELECT 
            camas.numero_cama AS 'Número de Cama',
            camas.ubicacion AS 'Ubicación',
            camas.id_paciente AS 'ID del Paciente',
            CONCAT(pacientes.nombre, ' ', pacientes.apellido) AS 'Nombre del Paciente',
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, pacientes.fecha_nacimiento, CURDATE()) < 1 THEN 
                    CONCAT(TIMESTAMPDIFF(MONTH, pacientes.fecha_nacimiento, CURDATE()), ' meses')
                ELSE 
                    CONCAT(TIMESTAMPDIFF(YEAR, pacientes.fecha_nacimiento, CURDATE()), ' años')
            END AS 'Edad del Paciente'
        FROM 
            camas
        LEFT JOIN 
            pacientes ON camas.id_paciente = pacientes.id
        WHERE 
            CONCAT(pacientes.nombre, ' ', pacientes.apellido) LIKE ? 
            OR camas.numero_cama LIKE ?
        ORDER BY 
            REGEXP_REPLACE(camas.numero_cama, '[^0-9]', '') + 0, 
            CASE 
                WHEN REGEXP_REPLACE(camas.numero_cama, '[^a-zA-Z]', '') <> '' 
                THEN REGEXP_REPLACE(camas.numero_cama, '[0-9]', '') 
                ELSE '' 
            END"; // Ordenar alfanuméricamente

$stmt = $conn->prepare($sql);
$search_param = "%" . $search_query . "%";
$stmt->bind_param("ss", $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();
?>
    <?php include "../verificar_sesion.php";
// Incluye el archivo de verificación de sesión
?>
        <!DOCTYPE html>
        <html lang="es">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Hospital Corea </title>
            <link rel="stylesheet" href="../css/gestion.css">
            <style>
                /* Tipografía */
                body {
                    font-family: 'Open Sans', sans-serif;
                    font-size: 16px;
                    line-height: 1.6;
                    color: #333;
                }
                h1, h2, h3 {
                    font-family: 'Montserrat', sans-serif;
                }
        
                /* Paleta de Colores */
                .header {
                    background-image: url('../images/imagen4.jpg');
                    background-size: cover;
                    color: white;
                    padding: 20px;
                    position: relative; /* Añadido para posicionar el botón en la esquina */
                }
                .header h1 {
                    color: white;
                    text-align: left; /* Título no centrado */
                    margin: 0;
                    font-size: 24px;
                }
                .header .logout-button {
                    position: absolute;
                    top: 20px;
                    right: 20px;
                    background-color: #e53935; /* Rojo */
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    cursor: pointer;
                    font-size: 16px;
                }
                .form-container h2 {
                    background-color: #e53935; /* Rojo */
                    color: white;
                    padding: 15px;
                    text-align: left;
                    margin-bottom: 20px;
                    font-size: 20px;
                }
                .search-container input[type="text"] {
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    margin-right: 10px;
                }
                .search-container button {
                    background-color: #e53935; /* Rojo */
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    cursor: pointer;
                    border-radius: 4px;
                }
                .table-container table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .table-container th, .table-container td {
                    border: 1px solid #ddd;
                    padding: 10px;
                }
                .table-container th {
                    background-color: #e53935; /* Gris claro */
                }
                .table-container td {
                    text-align: left;
                }
                .delete-button {
                    background-color: #e53935; /* Rojo */
                    color: white;
                    border: none;
                    padding: 5px 10px;
                    cursor: pointer;
                    border-radius: 4px;
                }
                .delete-button:before {
                    content: 'X';
                }
                .table-container {
            transition: opacity 0.3s ease-in-out;
            opacity: 1;
        }
        .table-container.hidden {
            opacity: 0;
        }
        /* Estilos para el modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .modal-content {
            background-color: white; /* Fondo blanco */
            padding: 20px;
            border-radius: 10px; /* Bordes redondeados */
            width: 50%;
            max-width: 600px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); /* Sombra sutil */
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        /* Cabecera del modal con fondo rojo */
        .modal-header {
            background-color: #e53935;
            padding: 10px;
            color: white;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 20px;
        }
        
        /* Botón de cierre */
        .modal-close {
            background-color: transparent;
            color: white;
            border: none;
            font-size: 20px;
            cursor: pointer;
        }
        
        .modal-close:hover {
            color: #ffcccc;
        }
        
        /* Cuerpo del modal */
        .modal-body {
            padding: 20px;
            flex-grow: 1;
        }
        
        /* Botones del modal */
        .modal-buttons {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .modal-buttons button {
            background-color: #e53935; /* Rojo */
            color: white;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 5px;
            margin-left: 10px;
        }
        
        .modal-buttons button:hover {
            background-color: #d32f2f; /* Sombra más oscura en hover */
        }
        
        /* Estilo específico para el botón "Guardar" */
        .modal-buttons .save-button {
            background-color: #4caf50; /* Verde */
        }
        
        .modal-buttons .save-button:hover {
            background-color: #388e3c; /* Verde oscuro en hover */
        }
        
        /* Estilo para el área de escritura de solicitud de transferencia */
        textarea {
            width: 100%;
            height: 100px;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            resize: none;
        }
        
        .complete-button {
            background-color: transparent; /* Fondo transparente */
            color: #007BFF; /* Azul */
            border: none;
            padding: 5px;
            cursor: pointer;
            font-size: 20px; /* Tamaño del ícono */
            transition: color 0.3s ease;
        }
        
        .complete-button:hover {
            color: #0056b3; /* Azul oscuro al pasar el mouse */
        }
        
        .complete-button i {
            pointer-events: none; /* Para que solo el botón sea clickeable, no el ícono */
        }
        .hospital-img {
            position: absolute;
    bottom: 10px; /* Espacio desde el borde inferior */
    right: 10px;  /* Espacio desde el borde derecho */
    width: 300px; /* Ajusta el tamaño de la imagen */
    height: auto; /* Mantén la proporción de la imagen */
}

            </style>
            <script>
                function confirmDelete(id) {
                    if (confirm("¿Estás seguro de que quieres eliminar a este paciente?")) {
                        document.getElementById('deleteForm_' + id).submit();
                    }
                }
            </script>
            <script src="http://localhost:3000/socket.io/socket.io.js"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

        </head>

        <body>
            <div class="container">
                <div class="header">
                    <button class="logout-button" onclick="window.location.href='../php/logout.php'">Cerrar Sesión</button>
                    <img src="../images/imagen5.png" alt="Logo" class="logo">
                  <!--  <img src="../images/hospitalcorea.jpg" alt="Imagen Hospital Corea" class="hospital-img">-->

                    <h1>HOSPITAL COREA</h1>
                </div>

                <!-- Formulario para añadir pacientes -->
                <div class="form-container">
                    <h2>Añadir Paciente</h2>
                    <form method="POST">
                        <input type="text" name="nombre" placeholder="Nombre" required>
                        <input type="text" name="apellido" placeholder="Apellido" required>
                        <input type="date" name="fecha_nacimiento" required>
                        <select name="sexo" required>
                            <option value="">Selecciona el sexo</option>
                            <option value="M">Masculino</option>
                            <option value="F">Femenino</option>
                        </select>
                        <select name="cama">
                            <option value="">Selecciona una cama</option>
                            <?php
                            $sql_camas =
                                "SELECT numero_cama, ubicacion FROM camas WHERE estado = 'libre'";
                            $result_camas = $conn->query($sql_camas);
                            while ($row = $result_camas->fetch_assoc()) {
                                echo "<option value=\"" .
                                    $row["numero_cama"] .
                                    "\">Cama: " .
                                    $row["numero_cama"] .
                                    " - " .
                                    $row["ubicacion"] .
                                    "</option>";
                            }
                            ?>
                        </select>
                        <button type="submit" name="add_patient">Añadir Paciente</button>
                    </form>
                </div>

                <!-- Formulario de búsqueda -->
                <div class="search-container">
                    <form method="POST">
                        <input type="text" name="search" placeholder="Buscar paciente o cama">
                        <button type="submit">Buscar</button>
                        <button type="button" onclick="location.href='emergencia.php'">Ir a Emergencia</button>
                        <button type="button" onclick="location.href='registro.php'">Ir a Registro</button>
                    </form>
                </div>

                <!-- Tabla de pacientes -->
                <div id="loadingIndicator" style="display: none;">
                    <img src="../images/loading.gif" alt="Cargando..." style="width: 30px; height: 30px;">
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Número de Cama</th>
                                <th>Ubicación</th>
                                <th>Nombre del Paciente</th>
                                <th>Edad del Paciente</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $row["Número de Cama"] ?? ""
                                        ); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $row["Ubicación"] ?? ""
                                        ); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $row["Nombre del Paciente"] ?? ""
                                        ); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(
                                            $row["Edad del Paciente"] ?? ""
                                        ); ?>
                                    </td>
                                    <td>
    <form id="deleteForm_<?php echo $row["ID del Paciente"]; ?>" method="POST" style="display:inline;">
        <input type="hidden" name="delete_patient" value="<?php echo $row["ID del Paciente"]; ?>">
        <button type="button" class="delete-button" onclick="confirmDelete('<?php echo $row["ID del Paciente"]; ?>')"></button>
    </form>
    <!-- Botón para completar datos con ícono -->
    <button type="button" class="complete-button" onclick="openModal(<?php echo $row['ID del Paciente']; ?>)">
        <i class="fas fa-file-alt"></i>
        <!-- Ícono de formulario -->
    </button>
</td>



                                </tr>
                                <?php endwhile; ?>
                        </tbody>
                        <!-- Modal para completar datos del paciente -->
                        <div id="completeModal" class="modal" style="display:none;">
                            <div class="modal-content">
                                <h2>Completar Datos del Paciente</h2>
                                <form id="completeForm">
                                    <input type="hidden" name="id_paciente" id="id_paciente">

                                    <label for="nombre">Nombre</label>
                                    <input type="text" name="nombre" id="nombre" required>

                                    <label for="apellido">Apellido</label>
                                    <input type="text" name="apellido" id="apellido" required>

                                    <label for="fecha_nacimiento">Fecha de Nacimiento</label>
                                    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required>

                                    <label for="sexo">Sexo</label>
                                    <select name="sexo" id="sexo" required>
                                        <option value="M">Masculino</option>
                                        <option value="F">Femenino</option>
                                    </select>

                                    <label for="direccion">Dirección</label>
                                    <input type="text" name="direccion" id="direccion">

                                    <label for="telefono">Teléfono</label>
                                    <input type="text" name="telefono" id="telefono">

                                    <label for="historial_medico">Historial Médico</label>
                                    <textarea name="historial_medico" id="historial_medico"></textarea>

                                    <label for="fecha_ingreso">Fecha de Ingreso</label>
                                    <input type="date" name="fecha_ingreso" id="fecha_ingreso" required>

                                    <!-- Campo para la solicitud de transferencia -->
                                    <label for="solicitud_transferencia">Solicitud de Transferencia</label>
                                    <textarea name="solicitud_transferencia" id="solicitud_transferencia"></textarea>

                                    <!-- Campo para el estado de transferencia -->
                                    <label for="estado_transferencia">Estado de Transferencia:</label>
                                    <select name="estado_transferencia" id="estado_transferencia">
                                        <option value="Ninguna">Ninguna</option>
                                        <option value="Solicitar">Solicitar</option>
                                    </select>
                                    <span id="estado_actual" style="display: none;"></span>>

                                    <div class="modal-buttons">
                                        <button type="button" onclick="closeModal()">Cancelar</button>
                                        <button type="submit">Guardar</button>
                                    </div>
                                </form>
                            </div>
                        </div>


                        <script>
                            document.getElementById('sendTransferRequest').onclick = function() {
                                // Obtener los datos del formulario
                                const formData = new FormData(document.getElementById('completeForm'));
                        
                                // Enviar la solicitud de transferencia
                                fetch('../Centro Coordinador/centro_coordinador.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(response => {
                                    if (response.ok) {
                                        alert('Solicitud de transferencia enviada correctamente.');
                                        closeModal();
                                    } else {
                                        alert('Error al enviar la solicitud de transferencia.');
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('Hubo un problema con la solicitud.');
                                });
                            };
                        </script>


                    </table>
                </div>
            </div>
            <script>
                function fetchBeds() {
                    // Obtener el valor seleccionado antes de la actualización
                    const camaSelect = document.querySelector('select[name="cama"]');
                    const selectedBed = camaSelect.value;
                
                    fetch('controladores/fetch_beds.php')
                        .then(response => response.json())
                        .then(data => {
                            camaSelect.innerHTML = '<option value="">Selecciona una cama</option>'; // Limpiar opciones
                
                            data.forEach(cama => {
                                const option = document.createElement('option');
                                option.value = cama.numero_cama;
                                option.textContent = `Cama: ${cama.numero_cama} - ${cama.ubicacion}`;
                                camaSelect.appendChild(option);
                            });
                
                            // Verificar si la cama seleccionada todavía existe
                            if (Array.from(camaSelect.options).some(option => option.value === selectedBed)) {
                                camaSelect.value = selectedBed; // Restaurar la selección
                            }
                        })
                        .catch(error => console.error('Error al cargar camas:', error));
                }
                
                // Cargar camas al cargar la página
                fetchBeds();
                
                // Realiza el polling cada 3 segundos para actualizar las camas
                setInterval(fetchBeds, 3000);
                
                
                
                function fetchData() {
                    const tableContainer = document.querySelector('.table-container');
                    tableContainer.classList.add('hidden'); // Ocultar la tabla con transición
                
                    fetch('controladores/fetch_data.php', {
                        method: 'POST',
                        body: new URLSearchParams({
                            'search': '<?php echo $search_query; ?>' // Para mantener la búsqueda activa
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Limpia la tabla actual
                        const tbody = document.querySelector('.table-container tbody');
                        tbody.innerHTML = '';
                
                        // Rellena la tabla con los nuevos datos
                        data.forEach(row => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>${row['Número de Cama'] || ''}</td>
                                <td>${row['Ubicación'] || ''}</td>
                                <td>${row['Nombre del Paciente'] || ''}</td>
                                <td>${row['Edad del Paciente'] || ''}</td>
                <td>
                    <form id="deleteForm_${row['ID del Paciente']}" method="POST" style="display:inline;">
                        <input type="hidden" name="delete_patient" value="${row['ID del Paciente']}">
                        <button type="button" class="delete-button" onclick="confirmDelete('${row['ID del Paciente']}')"></button>
                    </form>
                
                    <!-- Botón para completar datos con ícono -->
                    <button type="button" class="complete-button" onclick="openModal(${row['ID del Paciente']})">
                        <i class="fas fa-file-alt"></i> <!-- Ícono de formulario -->
                    </button>
                </td>
                
                        
                            `;
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(error => console.error('Error:', error))
                    .finally(() => {
                        tableContainer.classList.remove('hidden'); // Mostrar la tabla con transición
                    });
                }
                
                    // Realiza el polling cada 10 segundos
                    setInterval(fetchData, 3000);
            </script>
            <script>
                // Conectar al servidor de WebSocket
                const socket = io('http://localhost:3000');
            
                // Escuchar eventos de nuevos pacientes
                socket.on('nuevo_paciente', (data) => {
                    console.log('Nuevo paciente añadido:', data);
                    fetchData(); // Actualiza la tabla
                });
            
                // Escuchar eventos de eliminación de pacientes
                socket.on('paciente_eliminado', (data) => {
                    console.log('Paciente eliminado:', data);
                    fetchData(); // Actualiza la tabla
                });
            </script>
            <script>
                function openModal(idPaciente) {
                    // Llenar el modal con los datos del paciente
                    fetch(`controladores/get_patient.php?id=${idPaciente}`)
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('id_paciente').value = data.id;
                            document.getElementById('nombre').value = data.nombre;
                            document.getElementById('apellido').value = data.apellido;
                            document.getElementById('fecha_nacimiento').value = data.fecha_nacimiento;
                            document.getElementById('sexo').value = data.sexo;
                            document.getElementById('direccion').value = data.direccion;
                            document.getElementById('telefono').value = data.telefono;
                            document.getElementById('historial_medico').value = data.historial_medico;
                            document.getElementById('fecha_ingreso').value = data.fecha_ingreso;
                
                         // Cargar el estado de transferencia
                         const estadoTransferencia = data.estado_transferencia || 'Ninguna'; // Si no hay estado, por defecto es "Ninguna"
                
                // Establecer el valor del select según el estado de transferencia
                const selectEstado = document.getElementById('estado_transferencia');
                selectEstado.value = estadoTransferencia; // Asignar el estado actual, aunque no esté en las opciones.
                
                // Mostrar el estado actual si es diferente de "Ninguna" o "Solicitar"
                const estadoActualSpan = document.getElementById('estado_actual');
                if (estadoTransferencia !== 'Ninguna' && estadoTransferencia !== 'Solicitar') {
                    estadoActualSpan.style.display = 'inline';
                    estadoActualSpan.innerText = `Estado actual: ${estadoTransferencia}`; // Mostrar el estado actual.
                } else {
                    estadoActualSpan.style.display = 'none'; // Ocultar el texto del estado actual.
                }
                
                            // Mostrar el modal
                            document.getElementById('completeModal').style.display = 'flex';
                        })
                        .catch(error => console.error('Error al obtener los datos del paciente:', error));
                }
                
                function closeModal() {
                    document.getElementById('completeModal').style.display = 'none';
                }
                
                document.getElementById('completeForm').addEventListener('submit', function(event) {
                    event.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    fetch('controladores/update_patient.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(result => {
                        alert(result);
                        closeModal(); // Cerrar el modal después de guardar
                        fetchData();  // Actualizar la tabla de pacientes
                    })
                    .catch(error => console.error('Error al guardar los datos:', error));
                });
            </script>
        </body>

        </html>