import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../services/api_service.dart';
import '../widgets/custom_loading_widget.dart';

class ExploreScreen extends StatefulWidget {
  final Function(List<String>) onTableSelected;

  const ExploreScreen({super.key, required this.onTableSelected});

  @override
  State<ExploreScreen> createState() => _ExploreScreenState();
}

class _ExploreScreenState extends State<ExploreScreen> {
  final ApiService _apiService = ApiService();
  List<String> _tables = [];
  Set<String> _selectedTables = {};
  List<String> _columns = [];
  List<Map<String, dynamic>> _rows = [];
  bool _isLoading = false;
  bool _isLoadingData = false;

  @override
  void initState() {
    super.initState();
    _fetchTables();
  }
  
  Future<void> _fetchTables() async {
    setState(() {
      _isLoading = true;
    });
    try {
      final tables = await _apiService.getTables();
      setState(() {
        _tables = List<String>.from(tables);
      });
    } catch (e) {
      print("Error fetching tables: $e");
    } finally {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _fetchTableData(String table) async {
    // Just fetch data to view it. Selection is handled by toggle.
    setState(() {
      _isLoadingData = true;
      _columns = [];
      _rows = [];
    });
    try {
      final data = await _apiService.getTableData(table);
      setState(() {
        _columns = List<String>.from(data['columns']);
        _rows = List<Map<String, dynamic>>.from(data['rows']);
      });
    } catch (e) {
      print("Error fetching table data: $e");
    } finally {
      setState(() => _isLoadingData = false);
    }
  }

  void _toggleTableSelection(String table) {
    setState(() {
      if (_selectedTables.contains(table)) {
        _selectedTables.remove(table);
      } else {
        _selectedTables.add(table);
        // Automatically view the data of the table we just selected
        _fetchTableData(table);
      }
    });
    // Notify parent with the full list of selected tables
    widget.onTableSelected(_selectedTables.toList());
  }

  Future<void> _deleteTable(String table) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: const Color(0xFF27272A),
        title: Text('Confirmar Exclusão', style: GoogleFonts.outfit(color: Colors.white)),
        content: Text('Tem certeza que deseja excluir a tabela "$table"? Esta ação não pode ser desfeita.', style: GoogleFonts.inter(color: Colors.grey[300])),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('Cancelar', style: GoogleFonts.inter(color: Colors.grey)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text('Excluir', style: GoogleFonts.inter(color: Colors.red)),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      setState(() => _isLoading = true);
      try {
        await _apiService.dropTable(table);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Tabela "$table" excluída com sucesso!'), backgroundColor: Colors.green),
        );
        // Refresh list
        _fetchTables();
        // Clear selection if deleted
        if (_selectedTables.contains(table)) {
           _selectedTables.remove(table);
           _columns = [];
           _rows = [];
        }
      } catch (e) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Erro ao excluir: $e'), backgroundColor: Colors.red),
        );
      } finally {
        setState(() => _isLoading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isMobile = MediaQuery.of(context).size.width < 800;
    
    // On mobile, if we are viewing data, just show the grid
    if (isMobile && (_columns.isNotEmpty || _isLoadingData)) {
      return Container(
        color: const Color(0xFF0F0F12),
        child: SafeArea(
          child: Column(
            children: [
              // Header with Back Button for Mobile
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: const BoxDecoration(
                  color: Color(0xFF0F0F12),
                  border: Border(bottom: BorderSide(color: Color(0xFF27272A))),
                ),
                child: Row(
                  children: [
                     Container(
                       decoration: BoxDecoration(
                         color: const Color(0xFF27272A),
                         borderRadius: BorderRadius.circular(8),
                       ),
                       child: IconButton(
                         icon: const Icon(Icons.arrow_back, color: Colors.white70, size: 20),
                         padding: EdgeInsets.zero,
                         constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                         onPressed: () {
                           setState(() {
                             _columns = [];
                             _rows = [];
                             _isLoadingData = false;
                           });
                         },
                       ),
                     ),
                     const SizedBox(width: 16),
                     Expanded(
                       child: Column(
                         crossAxisAlignment: CrossAxisAlignment.start,
                         children: [
                           Text(
                              _selectedTables.isNotEmpty ? _selectedTables.last : "Data View",
                              style: GoogleFonts.outfit(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600),
                              overflow: TextOverflow.ellipsis,
                           ),
                           if (_selectedTables.length > 1)
                              Text(
                                "+${_selectedTables.length - 1} other tables",
                                style: GoogleFonts.inter(color: Colors.grey[500], fontSize: 11),
                              )
                         ],
                       ),
                     ),
                     if (_isLoadingData)
                       const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF818CF8)))),
                  ],
                ),
              ),
              Expanded(child: _buildContent()),
            ],
          ),
        ),
      );
    }
    
    return Container(
      color: const Color(0xFF0F0F12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Left Panel: Table List
          Expanded(
            flex: isMobile ? 1 : 0,
            child: Container(
              width: isMobile ? double.infinity : 280,
              decoration: BoxDecoration(
                border: Border(right: BorderSide(color: Colors.white.withOpacity(0.05))),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.fromLTRB(24, 24, 24, 16),
                    child: Text('Database Tables', style: GoogleFonts.outfit(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                  ),
                  Expanded(
                    child: _isLoading 
                      ? const Center(child: CustomLoadingWidget()) 
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                          itemCount: _tables.length,
                          separatorBuilder: (c, i) => const SizedBox(height: 4),
                          itemBuilder: (context, index) {
                            final table = _tables[index];
                            final isSelected = _selectedTables.contains(table);
                            return InkWell(
                              onTap: () => _toggleTableSelection(table),
                              borderRadius: BorderRadius.circular(8),
                              child: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                                decoration: BoxDecoration(
                                  color: isSelected ? const Color(0xFF27272A) : Colors.transparent,
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Row(
                                  children: [
                                    Icon(
                                      isSelected ? Icons.check_circle : Icons.circle_outlined, 
                                      color: isSelected ? const Color(0xFF818CF8) : Colors.grey[700], 
                                      size: 18
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Text(
                                        table,
                                        style: GoogleFonts.inter(
                                          color: isSelected ? Colors.white : Colors.grey[400],
                                          fontWeight: isSelected ? FontWeight.w500 : FontWeight.w400,
                                          fontSize: 14
                                        ),
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ),
                                    // Delete Button
                                    if (isSelected)
                                      IconButton(
                                        icon: const Icon(Icons.delete_outline, color: Colors.grey, size: 16),
                                        onPressed: () => _deleteTable(table),
                                        padding: EdgeInsets.zero,
                                        constraints: const BoxConstraints(),
                                        splashRadius: 16,
                                      ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                  ),
                ],
              ),
            ),
          ),

          // Right Panel: Data Grid (Hidden on Mobile list view)
          if (!isMobile)
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
                    decoration: const BoxDecoration(
                      border: Border(bottom: BorderSide(color: Color(0xFF27272A))),
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _selectedTables.isNotEmpty 
                                  ? '${_selectedTables.length} tables selected' 
                                  : 'Explore Data',
                                style: GoogleFonts.outfit(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w600),
                                overflow: TextOverflow.ellipsis,
                              ),
                              if (_selectedTables.isNotEmpty)
                                 Padding(
                                   padding: const EdgeInsets.only(top: 4),
                                   child: Text(
                                    _selectedTables.join(', '), 
                                    style: GoogleFonts.inter(color: Colors.grey[500], fontSize: 12),
                                    overflow: TextOverflow.ellipsis,
                                    maxLines: 1,
                                   ),
                                 ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 16),
                        if (_isLoadingData)
                          const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF818CF8)))),
                      ],
                    ),
                  ),
                  Expanded(
                    child: _buildContent(),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildContent() {
    if (_columns.isEmpty && !_isLoadingData) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.table_chart_outlined, size: 64, color: Colors.grey),
            const SizedBox(height: 16),
            Text('Selecione uma tabela para visualizar', style: GoogleFonts.inter(color: Colors.grey[500], fontSize: 16)),
            const SizedBox(height: 8),
            Text('Selecione múltiplas para dar contexto à IA', style: GoogleFonts.inter(color: Colors.grey[700], fontSize: 12)),
          ],
        ),
      );
    }

    if (_isLoadingData && _columns.isEmpty) {
      return const Center(child: CustomLoadingWidget());
    }

    // Se temos colunas, mostramos a tabela
    // Se temos colunas, mostramos a tabela
    return LayoutBuilder(
      builder: (context, constraints) {
        return SingleChildScrollView(
          scrollDirection: Axis.vertical,
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: ConstrainedBox(
              constraints: BoxConstraints(
                minWidth: constraints.maxWidth,
              ),
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Container(
                  decoration: BoxDecoration(
                    color: const Color(0xFF18181B), // Zinc 900
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: Colors.white.withOpacity(0.05)),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.2),
                        blurRadius: 10,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(16),
                    child: Theme(
                      data: Theme.of(context).copyWith(
                        dividerColor: Colors.white.withOpacity(0.05),
                      ),
                      child: DataTable(
                        headingRowColor: MaterialStateProperty.all(const Color(0xFF27272A)), // Slightly lighter for header
                        dataRowColor: MaterialStateProperty.all(Colors.transparent), // Transparent to show container color
                        columnSpacing: 24,
                        horizontalMargin: 24,
                        columns: _columns.map((col) => DataColumn(
                            label: Flexible(
                              child: Text(
                                col, 
                                style: GoogleFonts.inter(color: Colors.grey[400], fontWeight: FontWeight.w600),
                                overflow: TextOverflow.ellipsis,
                              ),
                            )
                          )).toList(),
                        rows: _rows.map((row) => DataRow(
                          cells: _columns.map((col) => DataCell(
                            Container(
                              constraints: const BoxConstraints(maxWidth: 200),
                              child: Text(
                                row[col]?.toString() ?? 'NULL', 
                                style: GoogleFonts.inter(color: Colors.white70),
                                overflow: TextOverflow.ellipsis,
                              ),
                            )
                          )).toList()
                        )).toList(),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        );
      }
    );
  }
}